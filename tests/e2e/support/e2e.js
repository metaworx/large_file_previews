// This app's own id, as it appears in bundle paths and therefore in the
// stack of anything it throws.
const APP_ID = 'large_file_previews'

// How occ is called where CYPRESS_occ does not say: the CI layout, with
// Cypress in the repository root and the server in ./nextcloud.
const DEFAULT_OCC = 'php nextcloud/occ'

Cypress.on( 'uncaught:exception', ( err ) => {
	// Chrome reports an observer that could not deliver every notification
	// within one frame as an error; it is a notice, not a fault, and it
	// carries the page URL as its only frame, which would name this app.
	if ( err.message.includes( 'ResizeObserver loop' ) )
	{
		return false
	}

	// Nextcloud's own apps throw benign unhandled rejections while a fresh
	// install loads (a missing upload folder, 404s on their OCS endpoints).
	// Those are not this suite's business; anything this app throws is.
	const ours = `${ err.message }\n${ err.stack || '' }`.includes( APP_ID )

	console.error(
		`${ ours ? 'Failing on' : 'Ignoring' } uncaught exception:`,
		err.message,
	)

	// Returning false is what tells Cypress not to fail the test.
	return ours
} )

/**
 * Run occ with the arguments given, through CYPRESS_occ.
 *
 * The arguments are one string, quoted by the caller where they need it.
 * Options go to cy.exec(); a non-zero exit fails the test unless they say
 * otherwise.
 */
Cypress.Commands.add( 'occ', ( args, options = {} ) => {
	// cy.env(), not Cypress.env(): this suite runs with allowCypressEnv off.
	return cy.env( [ 'occ' ] ).then( ( { occ } ) => cy.exec( `${ occ || DEFAULT_OCC } ${ args }`, options ) )
} )

/**
 * The administrator's credentials: CYPRESS_NC_ADMIN_USER and
 * CYPRESS_NC_ADMIN_PASSWORD, admin and admin where unset.
 */
Cypress.Commands.add( 'admin', () => {
	return cy.env( [ 'NC_ADMIN_USER', 'NC_ADMIN_PASSWORD' ] ).then( ( env ) => ( {
		user: env.NC_ADMIN_USER || 'admin',
		password: env.NC_ADMIN_PASSWORD || 'admin',
	} ) )
} )

// Every account this spec logs in as. A form login mints a browser token in
// oc_authtoken, and nothing in a test run ever ends that session; left
// alone, a suite collects hundreds of them on the administrator.
const loggedInAs = new Set()

Cypress.Commands.add( 'login', ( user = 'admin', password = 'admin' ) => {
	loggedInAs.add( user )
	cy.session( [ user, password ], () => {
		cy.visit( '/login' )
		cy.get( 'input[name="user"]' ).type( user )
		cy.get( 'input[name="password"]' ).type( `${ password }{enter}` )
		cy.url().should( 'not.include', '/login' )
	}, {
		// Restored cookies are asked whether they still hold, the way a test
		// uses them: a page navigation. Nextcloud re-checks a session's
		// token every five minutes and logs it out when the token is gone,
		// while cy.request() with the same cookies still answers through the
		// remember-me cookie.
		validate() {
			cy.visit( '/index.php/settings/user' )
			cy.url().should( 'not.include', '/login' )
		},
	} )
} )

/**
 * The administrator logged in, by the credentials cy.admin() reads.
 */
Cypress.Commands.add( 'loginAsAdmin', () => {
	return cy.admin().then( ( { user, password } ) => cy.login( user, password ) )
} )

// Delete the tokens this run's logins minted. Not by logging out: a session
// cannot be restored inside an after() hook. By name, through occ: every
// token the test browser makes carries "Cypress/" in its user agent, and no
// real browser's does, so the sweep never reaches a person's own session.
// Runs at the end of a spec, for what it minted, and at the start, for what
// the previous spec's own after() hooks minted later than its sweep.
const sweepCypressTokens = ( users ) => {
	for ( const user of users ) {
		cy.occ( `user:auth-tokens:list ${ user } --output=json`, { failOnNonZeroExit: false, log: false } )
			.then( ( { stdout } ) => {
				let tokens = []
				try {
					tokens = JSON.parse( stdout )
				} catch ( e ) {
					return
				}
				for ( const token of tokens ) {
					if ( String( token.name ).includes( 'Cypress/' ) ) {
						cy.occ( `user:auth-tokens:delete ${ user } ${ token.id }`, { failOnNonZeroExit: false, log: false } )
					}
				}
			} )
	}
}

before( () => {
	cy.admin().then( ( { user } ) => sweepCypressTokens( [ user ] ) )
} )

after( () => {
	sweepCypressTokens( loggedInAs )
	loggedInAs.clear()
} )

// ---------------------------------------------------------------------------
// Failure diagnostic
//
// Runs only for a test that has already failed. The payload goes through
// cy.task() because cy.log() does not reach stdout under `cypress run`,
// which is exactly where the context is needed.
// ---------------------------------------------------------------------------

const DIAG_LOG_LIMIT = 12

Cypress.on( 'window:before:load', ( win ) => {
	win.__diagLog = []
	for ( const level of [ 'error', 'warn' ] ) {
		const original = win.console[ level ]
		win.console[ level ] = ( ...args ) => {
			try {
				const line = args.map( String ).join( ' ' )
				// Every error is worth keeping; warnings only when they are ours.
				if ( level === 'error' || line.includes( APP_ID ) ) {
					if ( win.__diagLog.length < DIAG_LOG_LIMIT ) {
						win.__diagLog.push( `${ level }: ${ line }`.slice( 0, 300 ) )
					}
				}
			} catch ( e ) {
				// A console tap must never break the page under test.
			}
			original.apply( win.console, args )
		}
	}
} )

const collectDiagnostic = ( win, testTitle ) => {
	const payload = { test: testTitle }
	try {
		const doc = win.document
		const settings = doc.querySelector( '#lfp-admin-settings' )

		payload.url = win.location?.href ?? null
		// Absent means the page is not the settings, or the app's script was
		// not loaded; empty means it loaded and did not mount.
		payload.settings = settings
			? settings.textContent.replace( /\s+/g, ' ' ).trim().slice( 0, 400 )
			: null
		payload.scripts = [ ...doc.querySelectorAll( `script[src*="${ APP_ID }"]` ) ]
			.map( ( el ) => ( el.getAttribute( 'src' ) || '' ).split( '/' ).pop() )
		payload.consoleLog = win.__diagLog ?? []
	} catch ( e ) {
		payload.collectionError = String( e )
	}
	return payload
}

afterEach( function () {
	if ( this.currentTest?.state !== 'failed' ) {
		return
	}
	const testTitle = this.currentTest.fullTitle()
	cy.window( { log: false, timeout: 4000 } ).then( ( win ) => {
		cy.task( 'diag', collectDiagnostic( win, testTitle ), { log: false } )
	} )
} )
