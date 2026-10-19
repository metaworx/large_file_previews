// The settings page against a live server: what it saves is what occ reads
// back, and what is stored is what it shows. Each test clears what it sets,
// before and after, so any one of them runs alone.

const SETTINGS = '/index.php/settings/admin/additional'
const FIND_TIMEOUT = 60000

const EXTENSIONS = [ '7z', 'heic', 'pdf' ]

// A type that ships a minimum of its own, 50 MiB: the field shows what is
// stored, and the shipped value only as its placeholder.
const MIME = 'image/tiff'
const SHIPPED_MIN = '50 (as shipped)'

// @nextcloud/dialogs' toast container; its class is a CSS-module hash, so
// it is matched by the stable half of the name, as src/toast.css does.
const TOAST = '[class*="_toastContainer_"]'

const clearRules = () => {
	for ( const extension of EXTENSIONS ) {
		cy.occ( `lfp:config:unset --ext ${ extension }`, { failOnNonZeroExit: false, log: false } )
	}
	cy.occ( `lfp:config:unset --mime ${ MIME } --field min_size_mb`, { failOnNonZeroExit: false, log: false } )
	cy.occ( 'lfp:config:unset --mime application/pdf', { failOnNonZeroExit: false, log: false } )
	for ( const key of [ 'min_size_mb', 'timeout_seconds', 'preview_quality', 'preview_stream', 'bwrap_enabled' ] ) {
		cy.occ( `lfp:config:unset ${ key }`, { failOnNonZeroExit: false, log: false } )
	}
}

const openSettings = () => {
	cy.visit( SETTINGS )
	cy.get( '#lfp-admin-settings', { timeout: FIND_TIMEOUT } ).should( 'contain', 'What is processed' )
}

const addExtension = ( extension ) => {
	cy.get( 'input#lfp-new-extension' ).clear().type( extension )
	cy.get( 'input#lfp-new-extension' ).closest( 'form' ).contains( 'button', 'Add' ).click()
}

/** The resolved rows occ reports, as `lfp:config:show` has them. */
const resolved = () => cy.occ( 'lfp:config:show --output=json' )
	.then( ( { stdout } ) => JSON.parse( stdout ).resolved )

/** Whether two elements' boxes meet. */
const overlaps = ( a, b ) => {
	const r = a.getBoundingClientRect()
	const s = b.getBoundingClientRect()
	return r.left < s.right && s.left < r.right && r.top < s.bottom && s.top < r.bottom
}

describe( 'The settings page', () => {
	beforeEach( () => {
		clearRules()
		cy.loginAsAdmin()
		openSettings()
	} )

	after( () => {
		clearRules()
	} )

	it( 'warns in amber while nothing is processed, as installed', () => {
		cy.get( '.lfp-nothing-processed' )
			.should( 'contain', 'Nothing is processed: every MIME type is disabled.' )

		cy.occ( 'lfp:config:set --mime application/pdf --enabled=yes' )
		openSettings()
		cy.get( '.lfp-nothing-processed' ).should( 'not.exist' )
	} )

	it( 'refuses an extension whose type the app does not handle', () => {
		addExtension( '7z' )

		cy.get( TOAST ).should( 'contain', 'Files .7z are application/x-7z-compressed, a type this app does not handle.' )
		cy.get( 'li[data-extension="7z"]' ).should( 'not.exist' )
		cy.get( '.lfp-active' ).should( 'not.contain', '.7z' )
		resolved().then( ( rows ) => {
			expect( rows.map( ( row ) => row.extension ) ).not.to.include( '7z' )
		} )
	} )

	it( 'adds an extension whose type is off, and lists it as processed', () => {
		// HEIC is off by default, so the extension selects files the MIME type
		// does not: a row of its own in "What is processed".
		cy.get( '.lfp-active' ).should( 'not.contain', '.heic' )
		addExtension( 'heic' )

		cy.get( 'li[data-extension="heic"]', { timeout: FIND_TIMEOUT } )
			.should( 'contain', 'image/heic' )
			.and( 'contain', 'enabled' )
		// Its sizes are empty, and inherit HEIC's: a minimum of 50 MiB, no
		// maximum.
		cy.get( 'input[id="lfp-min-ext-heic"]' ).should( 'have.value', '' ).and( 'have.attr', 'placeholder', '50 (inherited)' )
		cy.get( 'input[id="lfp-max-ext-heic"]' ).should( 'have.value', '' ).and( 'have.attr', 'placeholder', 'no limit (inherited)' )
		cy.get( '.lfp-active tbody tr' ).filter( ':contains(".heic")' )
			.should( 'have.length', 1 )
			.and( 'contain', 'image/heic' )
		resolved().then( ( rows ) => {
			const row = rows.find( ( r ) => r.extension === 'heic' )
			expect( row?.enabled ).to.deep.equal( { value: true, source: 'ext' } )
		} )

		// Removed, it is gone from both tables, and from what occ reads.
		cy.get( 'li[data-extension="heic"]' ).contains( 'button', 'Remove' ).click()
		cy.get( 'li[data-extension="heic"]' ).should( 'not.exist' )
		cy.get( '.lfp-active' ).should( 'not.contain', '.heic' )
		resolved().then( ( rows ) => {
			expect( rows.map( ( row ) => row.extension ) ).not.to.include( 'heic' )
		} )
	} )

	it( 'says an extension its MIME type already selects has no effect', () => {
		// Every type ships off: PDF is switched on first, as an administrator
		// would.
		cy.occ( 'lfp:config:set --mime application/pdf --enabled=yes' )
		openSettings()
		addExtension( 'pdf' )

		cy.get( 'li[data-extension="pdf"]', { timeout: FIND_TIMEOUT } )
			.find( '.lfp-no-effect' )
			.should( 'contain', 'No effect: its MIME type already selects it' )
	} )

	it( 'shows a MIME type\'s stored minimum as stored, and occ reads the same', () => {
		const field = `input[id="lfp-min-${ MIME }"]`

		// Nothing stored: the field is empty, and what the type ships with is
		// its placeholder.
		cy.get( field ).should( 'have.value', '' ).and( 'have.attr', 'placeholder', SHIPPED_MIN )

		cy.get( field ).type( '7' )
		cy.get( field ).closest( '.lfp-number' ).find( '.lfp-number__save' ).click()
		cy.get( TOAST ).should( 'contain', 'Saved.' )

		cy.reload()
		cy.get( field, { timeout: FIND_TIMEOUT } ).should( 'have.value', '7' )
		cy.occ( `lfp:config:get --mime ${ MIME } --output=json` ).then( ( { stdout } ) => {
			expect( JSON.parse( stdout ).min_size_mb ).to.deep.equal( { value: 7, source: 'mime' } )
		} )

		// Reset: back to what the type ships with, the field empty again.
		cy.get( `li[data-mime="${ MIME }"]` ).find( '.lfp-reset' ).click()
		cy.get( field ).should( 'have.value', '' ).and( 'have.attr', 'placeholder', SHIPPED_MIN )
		cy.occ( `lfp:config:get --mime ${ MIME } --output=json` ).then( ( { stdout } ) => {
			expect( JSON.parse( stdout ).min_size_mb ).to.deep.equal( { value: 50, source: 'shipped' } )
		} )
	} )

	it( 'resets a default to what it ships with, and clears a limit back to its own', () => {
		const stored = ( key ) => cy.occ( 'lfp:config:show --output=json' )
			.then( ( { stdout } ) => JSON.parse( stdout ).stored[ key ] )

		cy.occ( 'lfp:config:set min_size_mb 5' )
		cy.occ( 'lfp:config:set timeout_seconds 45' )
		openSettings()

		// The default: shown as it is, with a Reset while it is stored.
		cy.get( 'input#lfp-min-size' ).should( 'have.value', '5' )
			.closest( '.lfp-number' ).contains( 'button', 'Reset' ).click()
		cy.get( 'input#lfp-min-size' ).should( 'have.value', '0' )
			.closest( '.lfp-number' ).find( '.lfp-number__reset' ).should( 'not.exist' )

		// A limit: emptied and saved, it follows its default again.
		cy.get( 'input#lfp-timeout_seconds' ).should( 'have.value', '45' ).clear()
		cy.get( 'input#lfp-timeout_seconds' ).closest( '.lfp-number' ).find( '.lfp-number__save' ).click()
		cy.get( 'input#lfp-timeout_seconds' ).should( 'have.value', '' ).and( 'have.attr', 'placeholder', '30 (default)' )
		cy.get( '[data-limit="timeout_enabled"]' ).should( 'contain', 'occ lfp:config:unset timeout_seconds' )

		stored( 'min_size_mb' ).should( 'deep.include', { value: 0, stored: false } )
		stored( 'timeout_seconds' ).should( 'deep.include', { value: 30, stored: false } )
	} )

	it( 'lists the providers Nextcloud tries for a type, this app first', () => {
		cy.get( `li[data-mime="${ MIME }"] .lfp-rule-providers .lfp-provider` ).first()
			.should( 'have.text', 'Large File Previews' )
			.and( 'have.attr', 'title', 'OCA\\LargeFilePreviews\\Preview\\LargeFileProvider' )
			.and( 'not.have.class', 'lfp-provider--before' )
		cy.get( '.lfp-provider--before' ).should( 'not.exist' )
	} )

	it( 'moves every type\'s providers to a row of their own once one chain no longer fits', () => {
		cy.get( '.lfp-rules' ).first().as( 'list' )
			.should( 'not.have.class', 'lfp-rules--providers-own-row' )

		// The server's chains are short: one is lengthened here, as a server
		// with more providers would have it, and the window resized, on which
		// the list measures again.
		cy.get( '@list' ).find( '.lfp-rule-providers' ).first().then( ( $providers ) => {
			$providers.append( ' › A provider with a long name › Another one as long › And a third one' )
		} )
		cy.viewport( 1270, 800 )

		cy.get( '@list' ).should( 'have.class', 'lfp-rules--providers-own-row' )
		// Every type's, not only the long one's: each beneath its name.
		cy.get( '@list' ).find( '.lfp-rule' ).each( ( $rule ) => {
			const head = $rule.find( '.lfp-rule__head' )[ 0 ].getBoundingClientRect()
			const providers = $rule.find( '.lfp-rule-providers' )[ 0 ].getBoundingClientRect()
			expect( providers.top ).to.be.at.least( head.bottom )
			expect( providers.left ).to.equal( head.left )
		} )
	} )

	it( 'fits a phone\'s width: nothing on the page is wider than it', () => {
		// An extension too, so that both lists of rules are on the page.
		cy.occ( 'lfp:config:set --ext heic --enabled=yes' )
		cy.viewport( 400, 800 )
		cy.reload()
		cy.get( 'li[data-extension="heic"]', { timeout: FIND_TIMEOUT } )

		cy.get( '#lfp-admin-settings' ).should( ( $page ) => {
			const page = $page[ 0 ]
			const right = page.getBoundingClientRect().right
			const outside = [ ...page.querySelectorAll( 'td, button, input, code' ) ]
				.filter( ( element ) => element.getBoundingClientRect().right > right + 1 )
				.map( ( element ) => `${ element.tagName } "${ element.textContent.trim().slice( 0, 40 ) }"` )

			expect( outside ).to.deep.equal( [] )
			expect( page.scrollWidth ).to.be.at.most( page.clientWidth )
		} )
	} )

	it( 'sets Nextcloud\'s JPEG quality, and clears it back to its default', () => {
		// Nextcloud's, not the app's: what the server had is put back after.
		cy.occ( 'config:app:get preview jpeg_quality', { failOnNonZeroExit: false } ).then( ( { exitCode, stdout } ) => {
			const had = exitCode === 0 ? stdout.trim() : null

			cy.occ( 'config:app:delete preview jpeg_quality' )
			openSettings()
			cy.get( 'input#lfp-jpeg_quality' ).should( 'have.value', '' ).and( 'have.attr', 'placeholder', '80 (default)' )

			cy.get( 'input#lfp-jpeg_quality' ).type( '70' )
			cy.get( 'input#lfp-jpeg_quality' ).closest( '.lfp-number' ).find( '.lfp-number__save' ).click()
			cy.get( '[data-nextcloud="jpeg_quality"]' ).should( 'contain', 'occ config:app:set preview jpeg_quality --value=70 --type=integer' )
			cy.occ( 'config:app:get preview jpeg_quality' ).its( 'stdout' ).invoke( 'trim' ).should( 'equal', '70' )

			cy.get( 'input#lfp-jpeg_quality' ).clear()
			cy.get( 'input#lfp-jpeg_quality' ).closest( '.lfp-number' ).find( '.lfp-number__save' ).click()
			cy.get( '[data-nextcloud="jpeg_quality"]' ).should( 'contain', 'occ config:app:delete preview jpeg_quality' )
			cy.occ( 'config:app:get preview jpeg_quality', { failOnNonZeroExit: false } ).its( 'exitCode' ).should( 'not.equal', 0 )

			if ( had !== null ) {
				cy.occ( `config:app:set preview jpeg_quality --value=${ had } --type=integer` )
			}
		} )
	} )

	it( 'sets the app\'s quality and switches the stream, as occ reads back', () => {
		const stored = ( key ) => cy.occ( 'lfp:config:show --output=json' )
			.then( ( { stdout } ) => JSON.parse( stdout ).stored[ key ] )

		cy.get( 'input#lfp-preview-quality' ).should( 'have.value', '' ).and( 'have.attr', 'placeholder', '95 (default)' )
		cy.get( 'input#lfp-preview-quality' ).type( '85' )
		cy.get( 'input#lfp-preview-quality' ).closest( '.lfp-number' ).find( '.lfp-number__save' ).click()
		cy.get( '[data-setting="preview_quality"]' ).should( 'contain', 'occ lfp:config:set preview_quality 85' )
		stored( 'preview_quality' ).should( 'deep.include', { value: 85, stored: true } )

		cy.get( '[data-setting="preview_stream"]' ).should( 'contain', 'occ lfp:config:set preview_stream true' )
			.find( '.lfp-named input[type="checkbox"]' ).first().click( { force: true } )
		cy.get( '[data-setting="preview_stream"]' ).should( 'contain', 'occ lfp:config:set preview_stream false' )
		stored( 'preview_stream' ).should( 'deep.include', { value: false, stored: true } )
	} )

	// Where bwrap cannot start a sandbox - the harness's container refuses
	// user namespaces, CI's server may have no bwrap - the switch says so in
	// the status, as missing; where it can, as available.
	it( 'switches the sandbox on, and the status says whether it can start one', () => {
		cy.get( '[data-limit="bwrap_enabled"]' ).should( 'contain', 'occ lfp:config:set bwrap_enabled false' )
			.find( '.lfp-named input[type="checkbox"]' ).first().click( { force: true } )
		cy.get( '[data-limit="bwrap_enabled"]' ).should( 'contain', 'occ lfp:config:set bwrap_enabled true' )
		cy.occ( 'lfp:config:show --output=json' ).then( ( { stdout } ) => {
			expect( JSON.parse( stdout ).stored.bwrap_enabled ).to.deep.include( { value: true, stored: true } )
		} )

		cy.reload()
		cy.contains( '.lfp-status tbody tr', 'bwrap', { timeout: FIND_TIMEOUT } ).invoke( 'attr', 'class' ).then( ( row ) => {
			cy.occ( 'lfp:check', { failOnNonZeroExit: false } ).its( 'stdout' ).then( ( stdout ) => {
				const line = stdout.split( '\n' ).find( ( l ) => / bwrap /.test( l ) ) || ''
				expect( row ).to.equal( line.includes( 'missing' ) ? 'lfp-status--missing' : 'lfp-status--ok' )
			} )
		} )
	} )

	it( 'keeps every help icon clear of every Save button', () => {
		cy.get( '.lfp-help-icon' ).should( 'have.length.greaterThan', 0 )
		cy.get( '.lfp-number__save' ).should( 'have.length.greaterThan', 0 )
		cy.get( '#lfp-admin-settings' ).then( ( $page ) => {
			const icons = [ ...$page[ 0 ].querySelectorAll( '.lfp-help-icon' ) ]
			const saves = [ ...$page[ 0 ].querySelectorAll( '.lfp-number__save' ) ]
			const clashes = []
			for ( const icon of icons ) {
				for ( const save of saves ) {
					if ( overlaps( icon, save ) ) {
						clashes.push( `${ icon.getAttribute( 'aria-label' ) } over ${ save.getAttribute( 'aria-label' ) }` )
					}
				}
			}
			expect( clashes ).to.deep.equal( [] )
		} )
	} )
} )
