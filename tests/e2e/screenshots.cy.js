/**
 * The screenshot set, as a spec.
 *
 * Not part of the suite: it runs only when asked, with `CYPRESS_capture=1`,
 * and without that it skips itself. It puts the instance into the state the
 * captures show - a light theme, a few rules - takes the shots into
 * `docs/Screenshots/`, and puts the instance back. Repeatable, so the set
 * can be retaken when the page changes rather than aging in place.
 *
 * No shot shows a missing program: a path set in system config must be an
 * executable file, so a program is missing only where its package is, and
 * the instance has every one.
 */

const SETTINGS = '/index.php/settings/admin/additional'
const FIND_TIMEOUT = 60000

/**
 * Every capture: one window, one theme, one locale (the browser's, forced
 * to en-US). 1600 wide, the headless window's own width (cypress.config.cjs):
 * a viewport wider than the window is scaled down, every shot with it.
 */
const VIEWPORT = { width: 1600, height: 900 }

/**
 * The rules the shots show, set by occ and cleared after: every type ships
 * off, so PDF and Illustrator are switched on as an administrator would.
 */
const RULES = [
	'lfp:config:set --mime application/pdf --enabled=yes',
	'lfp:config:set --mime application/illustrator --enabled=yes',
	'lfp:config:set --mime image/tiff --enabled=yes',
	'lfp:config:set --ext heic --enabled=yes --min=20',
	'lfp:config:set --ext tif --enabled=no',
]
const CLEAR = [
	'lfp:config:unset --mime application/pdf',
	'lfp:config:unset --mime application/illustrator',
	'lfp:config:unset --mime image/tiff',
	'lfp:config:unset --ext heic',
	'lfp:config:unset --ext tif',
]

/**
 * The backup the Backup-And-Restore shot reads: another server's, which
 * differs from the rules above in a limit, a type and an extension.
 */
const BACKUP = {
	app: 'large_file_previews',
	format: 1,
	exported: { version: '0.2.1', date: '2026-10-19T08:00:00+00:00' },
	settings: { timeout_seconds: 45, preview_quality: 90 },
	mimetypes: {
		'application/pdf': { enabled: true },
		'image/tiff': { enabled: true, min_size_mb: 100 },
	},
	extensions: { heic: { enabled: true, min_size_mb: 20 } },
}

/**
 * What a cropped shot should not show: the scrollbars at its edges, and the
 * navigation's toggle, which floats over the top left of the content and
 * would sit on a heading's first letter.
 */
const SHOT_STYLE = [
	'* { scrollbar-width: none !important; }',
	'*::-webkit-scrollbar { display: none !important; }',
	'[class*="app-navigation-toggle"] { visibility: hidden !important; }',
].join( '\n' )

let adminUser = 'admin'

/** What the administrator's theme was before this run; put back in after(). */
let adminThemeWas = null

/** Whether before() set the instance up; a skipped run has nothing to put back. */
let armed = false

/** Set the theme an account sees; `null` puts it back to the instance default. */
const theme = ( value ) => (
	value === null
		? cy.occ( `user:setting --delete ${ adminUser } theming enabled-themes`, { failOnNonZeroExit: false } )
		: cy.occ( `user:setting ${ adminUser } theming enabled-themes '${ value }'` )
)

const openSettings = ( cropped = true ) => {
	cy.viewport( VIEWPORT.width, VIEWPORT.height )
	cy.visit( SETTINGS )
	cy.get( '#lfp-admin-settings', { timeout: FIND_TIMEOUT } ).should( 'contain', 'What is processed' )
	if ( cropped ) {
		cy.document().then( ( doc ) => {
			const style = doc.createElement( 'style' )
			style.textContent = SHOT_STYLE
			doc.head.appendChild( style )
		} )
	}
	// Fonts and icons settle after the content.
	cy.wait( 500 )
}

/**
 * The viewport, cropped to what lies between a first and a last element,
 * scrolled so that the first is at the top: the settings scroll inside the
 * page, so an element capture would scroll the wrong container.
 */
const shotBetween = ( name, first, last, margin = 16, below = margin ) => {
	cy.get( first, { timeout: FIND_TIMEOUT } ).first().scrollIntoView( { offset: { top: -margin, left: 0 } } )
	cy.wait( 300 )
	cy.get( first ).first().then( ( $first ) => {
		cy.get( last ).last().then( ( $last ) => {
			const top = $first[ 0 ].getBoundingClientRect()
			const bottom = $last[ 0 ].getBoundingClientRect()
			const content = $first[ 0 ].closest( '#lfp-admin-settings' ).getBoundingClientRect()
			const x = Math.max( 0, Math.floor( content.left ) - margin )
			const y = Math.max( 0, Math.floor( top.top ) - margin )
			const width = Math.min( VIEWPORT.width - x, Math.ceil( content.width ) + 2 * margin )
			const height = Math.min( VIEWPORT.height - y, Math.ceil( bottom.bottom - top.top ) + margin + below )
			cy.screenshot( name, { capture: 'viewport', overwrite: true, clip: { x, y, width, height } } )
		} )
	} )
}

describe( 'Screenshots', () => {
	before( function () {
		const suite = this
		cy.env( [ 'capture' ] ).then( ( env ) => {
			if ( ! env.capture ) {
				// Not a test: a tool. Skipped unless asked for by name.
				suite.skip()
			}
		} )
		cy.admin().then( ( admin ) => {
			adminUser = admin.user
		} )
		cy.then( () => {
			armed = true
			// cy.exec() yields exitCode; there is no code.
			cy.occ( `user:setting ${ adminUser } theming enabled-themes`, { failOnNonZeroExit: false } ).then( ( { exitCode, stdout } ) => {
				adminThemeWas = exitCode === 0 && stdout.trim() !== '' ? stdout.trim() : null
			} )
			theme( JSON.stringify( [ 'light' ] ) )
			for ( const command of CLEAR ) {
				cy.occ( command, { failOnNonZeroExit: false } )
			}
			for ( const command of RULES ) {
				cy.occ( command )
			}
		} )
	} )

	after( () => {
		if ( ! armed ) {
			return
		}
		for ( const command of CLEAR ) {
			cy.occ( command, { failOnNonZeroExit: false } )
		}
		theme( adminThemeWas )
	} )

	beforeEach( () => {
		cy.loginAsAdmin()
	} )

	// The page as it opens, in Nextcloud's frame.
	it( 'Settings', () => {
		openSettings( false )
		cy.screenshot( 'Settings', { capture: 'viewport', overwrite: true } )
	} )

	it( 'What-Is-Processed', () => {
		openSettings()
		shotBetween( 'What-Is-Processed', '.lfp-types h3', '.lfp-active' )
	} )

	// Down to JPEG, the first with a provider after this app's: the whole
	// list, each type in four rows, is taller than the viewport.
	it( 'MIME-Types', () => {
		openSettings()
		shotBetween( 'MIME-Types', '.lfp-types h4:contains("MIME types")', 'li[data-mime="image/jpeg"]', 16, 1 )
	} )

	it( 'Extensions', () => {
		openSettings()
		shotBetween( 'Extensions', '.lfp-types h4:contains("Extensions")', '.lfp-types form.lfp-row' )
	} )

	it( 'Limits', () => {
		openSettings()
		// Down to the caps' last command: the section, each field with its
		// label above it, is taller than the viewport.
		shotBetween( 'Limits', '.lfp-limits', '.lfp-limits .lfp-occ:contains("prlimit_fsize_mb")' )
	} )

	// Down to the quality's command; Nextcloud's own settings follow, below
	// the viewport.
	it( 'Previews', () => {
		openSettings()
		shotBetween( 'Previews', '.lfp-previews h3', '[data-setting="preview_quality"]' )
	} )

	// A file read and not applied: its changes against what the rules above
	// stored, shown before anything is written. Nothing is applied, so the
	// instance is left as the other shots have it.
	it( 'Backup-And-Restore', () => {
		openSettings()
		cy.get( 'input.lfp-backup__file' ).selectFile( {
			contents: Cypress.Buffer.from( JSON.stringify( BACKUP ) ),
			fileName: 'large_file_previews-config.cloud.example.com.2026-10-19.json',
		}, { force: true } )
		cy.get( '.lfp-backup__changes', { timeout: FIND_TIMEOUT } ).should( 'contain', 'timeout_seconds' )
		shotBetween( 'Backup-And-Restore', '.lfp-backup h3', '.lfp-backup__apply' )
	} )
} )
