const appId = 'large_file_previews'
const appName = 'Large File Previews'

// The app's row in the Apps page's list: its name links to the app's
// details under /settings/apps/.
const appLink = `a[href*="/settings/apps/"][href*="${ appId }"]`

// The first request for the app list can be slow on a cold PHP worker.
const FIND_TIMEOUT = 60000

let adminPassword = 'admin'

const assertAppEnabledCli = () => {
	cy.occ( 'app:list --enabled' ).its( 'stdout' ).should( 'include', appId )
}

const assertAppDisabledCli = () => {
	cy.occ( 'app:list --disabled' ).its( 'stdout' ).should( 'include', appId )
}

// Fill the password confirmation where it appears. Enabling always asks;
// disabling asks only without a recent confirmation, so this one looks.
const confirmPasswordIfPrompted = () => {
	cy.wait( 1000 )
	cy.get( 'body' ).then( ( $body ) => {
		const $input = $body.find( 'input[type="password"]' )
		if ( $input.length > 0 ) {
			cy.wrap( $input ).type( adminPassword )
			cy.contains( 'button', 'Confirm' ).click()
		}
	} )
}

describe( 'Enabling the app', () => {
	before( () => {
		cy.admin().then( ( { password } ) => {
			adminPassword = password
		} )
	} )

	beforeEach( () => {
		cy.loginAsAdmin()
	} )

	it( 'enables the app on the Apps page, and its settings show', () => {
		// Disabled first: installed, it is then listed under "Disabled apps".
		cy.occ( `app:disable ${ appId }`, { failOnNonZeroExit: false } )
		assertAppDisabledCli()

		cy.visit( '/index.php/settings/apps/disabled' )
		cy.get( appLink, { timeout: FIND_TIMEOUT } )
			.closest( 'tr' )
			.should( 'exist' )
			.as( 'appRow' )
		cy.get( '@appRow' ).within( () => {
			cy.contains( 'button', 'Enable' ).click()
		} )

		// Enabling an app asks for the password every time.
		cy.get( 'input[type="password"]', { timeout: FIND_TIMEOUT } ).type( adminPassword )
		cy.contains( 'button', 'Confirm' ).click()
		cy.get( appLink ).should( 'not.exist' )

		cy.visit( '/index.php/settings/apps/enabled' )
		cy.get( appLink, { timeout: FIND_TIMEOUT } )
			.closest( 'tr' )
			.within( () => {
				cy.contains( 'button', 'Disable' ).should( 'exist' )
			} )
		assertAppEnabledCli()

		// The settings, under "Additional settings", name the version the
		// server runs.
		cy.occ( 'app:list --output=json' ).then( ( { stdout } ) => {
			const version = JSON.parse( stdout ).enabled[ appId ]
			cy.visit( '/index.php/settings/admin/additional' )
			cy.get( '#lfp-admin-settings', { timeout: FIND_TIMEOUT } )
				.should( 'contain', `${ appName }, v${ version }` )
		} )
	} )

	it( 'disables the app on the Apps page', () => {
		cy.occ( `app:enable ${ appId }`, { failOnNonZeroExit: false } )
		assertAppEnabledCli()

		cy.visit( '/index.php/settings/apps/enabled' )
		cy.get( appLink, { timeout: FIND_TIMEOUT } )
			.closest( 'tr' )
			.should( 'exist' )
			.as( 'appRow' )
		cy.get( '@appRow' ).within( () => {
			cy.contains( 'button', 'Disable' ).click()
		} )
		confirmPasswordIfPrompted()
		cy.get( appLink ).should( 'not.exist' )

		cy.visit( '/index.php/settings/apps/disabled' )
		cy.get( appLink, { timeout: FIND_TIMEOUT } )
			.closest( 'tr' )
			.within( () => {
				cy.contains( 'button', 'Enable' ).should( 'exist' )
			} )
		assertAppDisabledCli()
	} )

	after( () => {
		// Enabled again for the specs that follow.
		cy.occ( `app:enable ${ appId }`, { failOnNonZeroExit: false } )
	} )
} )
