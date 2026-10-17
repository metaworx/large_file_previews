// Backup and restore on the settings page, round trip: rules set by occ,
// downloaded, cleared, the file uploaded, its changes applied, and occ
// reading back what was there before. Each test clears what it sets,
// before and after.

const SETTINGS = '/index.php/settings/admin/additional'
const FIND_TIMEOUT = 60000

const RULES = [
	'lfp:config:set timeout_seconds 45',
	'lfp:config:set --mime image/tiff --enabled=yes --min=800',
	'lfp:config:set --ext tif --enabled=no',
]
const CLEAR = [
	'lfp:config:unset timeout_seconds',
	'lfp:config:unset --mime image/tiff',
	'lfp:config:unset --ext tif',
]

const clear = () => {
	for ( const command of CLEAR ) {
		cy.occ( command, { failOnNonZeroExit: false, log: false } )
	}
}

/** The app's part of what occ exports: what is stored, without when. */
const stored = () => cy.occ( 'lfp:config:export' ).then( ( { stdout } ) => {
	const exported = JSON.parse( stdout )
	return { settings: exported.settings, mimetypes: exported.mimetypes, extensions: exported.extensions }
} )

const openSettings = () => {
	cy.visit( SETTINGS )
	cy.get( '#lfp-admin-settings', { timeout: FIND_TIMEOUT } ).should( 'contain', 'Backup and restore' )
}

describe( 'Backup and restore', () => {
	beforeEach( () => {
		clear()
		cy.loginAsAdmin()
	} )

	after( () => {
		clear()
	} )

	it( 'downloads what is stored, and restores it from the file, its changes shown first', () => {
		for ( const command of RULES ) {
			cy.occ( command )
		}
		stored().as( 'before' )

		openSettings()
		cy.get( '.lfp-backup__download' ).click()

		const host = new URL( Cypress.config( 'baseUrl' ) ).hostname
		const day = new Date().toISOString().slice( 0, 10 )
		const file = `${ Cypress.config( 'downloadsFolder' ) }/large_file_previews-config.${ host }.${ day }.json`
		cy.readFile( file, { timeout: 20000 } ).its( 'settings' ).should( 'deep.equal', { timeout_seconds: 45 } )

		clear()
		openSettings()
		cy.get( 'input.lfp-backup__file' ).selectFile( file, { force: true } )

		cy.get( '.lfp-backup__changes', { timeout: FIND_TIMEOUT } )
			.should( 'contain', 'timeout_seconds' )
			.and( 'contain', 'image/tiff' )
			.and( 'contain', 'tif' )
		cy.get( '.lfp-backup__warnings' ).should( 'not.exist' )

		cy.get( '.lfp-backup__apply' ).click()
		cy.get( '.lfp-backup__read' ).should( 'not.exist' )

		cy.get( '@before' ).then( ( before ) => {
			stored().should( 'deep.equal', before )
		} )
	} )

	it( 'refuses a file it cannot read, and writes nothing', () => {
		openSettings()
		cy.get( 'input.lfp-backup__file' ).selectFile(
			{ contents: Cypress.Buffer.from( '{"app":"large_file_previews","format":1,"settings":{"timeout_seconds":9999}}' ), fileName: 'wrong.json' },
			{ force: true },
		)

		cy.get( '.lfp-backup__refused', { timeout: FIND_TIMEOUT } ).should( 'contain', 'wrong.json: timeout_seconds must be between 1 and 600.' )
		stored().its( 'settings' ).should( 'deep.equal', {} )
	} )
} )
