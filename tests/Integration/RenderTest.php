<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Integration;

use OCA\LargeFilePreviews\AppInfo\Application;
use OCA\LargeFilePreviews\Config\ConfigLexicon;
use OCA\LargeFilePreviews\Config\TypeRule;
use OCA\LargeFilePreviews\Preview\LargeFileProvider;
use OCA\LargeFilePreviews\Service\PrerequisitesService;
use OCA\LargeFilePreviews\SetupCheck\RendererCheck;
use OCP\IPreview;
use OCP\Server;
use OCP\SetupCheck\SetupResult;

/**
 * Real renders by pdftoppm, through the provider and through Nextcloud's own
 * preview generation, and the ways a file is declined or a render fails.
 * Needs pdftoppm on the server: the harness instance's extra packages, or
 * the CI job's poppler-utils.
 */
class RenderTest
    extends
    ServerTestCase
{

//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		if ( Server::get( PrerequisitesService::class )->blocking() )
		{
			self::markTestSkipped( 'Something the app needs is missing here; see occ large-file-previews:check.' );
		}
	}


//  other non-static methods

	public function testAPdfIsRenderedIntoTheBox(): void
	{
		$file     = $this->file( 'wide.pdf', PdfFixture::bytes( 400, 200 ) );
		$provider = Server::get( LargeFileProvider::class );

		self::assertTrue( $provider->isAvailable( $file ) );

		$image = $provider->getThumbnail( $file, 1000, 300 );

		self::assertNotNull( $image );
		self::assertTrue( $image->valid() );
		// 2:1, fitted into 1000 x 300 by its height.
		self::assertSame( 300, $image->height() );
		self::assertEqualsWithDelta( 600, $image->width(), 2 );
	}

	public function testAnIllustratorFileIsRenderedAsTheSamePdf(): void
	{
		$file = $this->file( 'logo.ai', PdfFixture::bytes( 200, 200 ) );

		self::assertSame( 'application/illustrator', $file->getMimetype() );

		$image = Server::get( LargeFileProvider::class )->getThumbnail( $file, 256, 256 );

		self::assertNotNull( $image );
		self::assertSame( 256, $image->width() );
	}

	/**
	 * Through Nextcloud's own generator: no other PDF provider needs to be
	 * there for a preview to exist.
	 */
	public function testNextcloudMakesItsPreviewFromIt(): void
	{
		$file    = $this->file( 'doc.pdf', PdfFixture::bytes() );
		$preview = Server::get( IPreview::class )->getPreview( $file, 256, 256 );

		self::assertGreaterThan( 0, $preview->getSize() );
		self::assertNull( $this->appConfig()->getValueArray( Application::APP_ID, ConfigLexicon::LAST_FAILURE ) ?: null );
	}

	public function testOutsideTheSizeWindowItIsDeclined(): void
	{
		$this->settings()->setExtensionRule( 'pdf', new TypeRule( null, 1 ) );

		self::assertFalse( Server::get( LargeFileProvider::class )->isAvailable( $this->file( 'small.pdf', PdfFixture::bytes() ) ) );
	}

	public function testAnImageTypeIsDeclinedUntilARendererShips(): void
	{
		$this->settings()->setMimeRule( 'image/png', new TypeRule( true ) );

		self::assertFalse( Server::get( LargeFileProvider::class )->isAvailable( $this->file( 'a.png', 'not really a png' ) ) );
	}

	public function testABrokenFileIsNullAndRecorded(): void
	{
		$file = $this->file( 'broken.pdf', "%PDF-1.4\nnot a pdf at all\n" );

		self::assertNull( Server::get( LargeFileProvider::class )->getThumbnail( $file, 256, 256 ) );

		$failure = $this->appConfig()->getValueArray( Application::APP_ID, ConfigLexicon::LAST_FAILURE );

		self::assertSame( 'application/pdf', $failure['mime'] ?? null );
		self::assertSame( 'failed', $failure['outcome'] ?? null );
		self::assertSame( SetupResult::WARNING, Server::get( RendererCheck::class )->run()->getSeverity() );
	}

	public function testTheSetupCheckIsGreen(): void
	{
		self::assertSame( SetupResult::SUCCESS, Server::get( RendererCheck::class )->run()->getSeverity() );
	}
}
