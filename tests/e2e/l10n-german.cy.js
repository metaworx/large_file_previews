// The settings page in German, both of Nextcloud's: de, which says du, and
// de_DE, which says Sie. The administrator's language is set for the run and
// restored after it; force_language, which CI sets to English and which
// outranks an account's own language, is lifted for the run and restored.

const SETTINGS = '/index.php/settings/admin/additional'
const FIND_TIMEOUT = 60000

let adminUser = 'admin'
let ownLanguage = null
let forcedLanguage = null

const setLanguage = ( language ) => {
	cy.occ( `user:setting ${ adminUser } core lang ${ language }` )
}

describe( 'The settings page in German', () => {
	before( () => {
		cy.admin().then( ( admin ) => {
			adminUser = admin.user
			// cy.exec() yields exitCode; there is no code.
			cy.occ( `user:setting ${ adminUser } core lang`, { failOnNonZeroExit: false } ).then( ( { exitCode, stdout } ) => {
				ownLanguage = exitCode === 0 ? stdout.trim() : null
			} )
		} )
		cy.occ( 'config:system:get force_language', { failOnNonZeroExit: false } ).then( ( { exitCode, stdout } ) => {
			forcedLanguage = exitCode === 0 ? stdout.trim() : null
			if ( forcedLanguage !== null ) {
				cy.occ( 'config:system:delete force_language' )
			}
		} )
	} )

	after( () => {
		if ( ownLanguage === null ) {
			cy.occ( `user:setting ${ adminUser } core lang --delete`, { failOnNonZeroExit: false } )
		} else {
			setLanguage( ownLanguage )
		}
		if ( forcedLanguage !== null ) {
			cy.occ( `config:system:set force_language --value=${ forcedLanguage }` )
		}
	} )

	for ( const language of [ 'de', 'de_DE' ] ) {
		it( `speaks ${ language }`, () => {
			setLanguage( language )
			cy.loginAsAdmin()
			cy.visit( SETTINGS )

			// A heading, a button and the status: the frontend's
			// translations, which l10n/<language>.js brings.
			cy.get( '#lfp-admin-settings', { timeout: FIND_TIMEOUT } )
				.should( 'contain', 'Was verarbeitet wird' )
				.and( 'contain', 'Speichern' )
				.and( 'contain', 'Alles, was die App braucht, ist vorhanden.' )
				.and( 'not.contain', 'What is processed' )
		} )
	}
} )
