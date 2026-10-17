<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Integration;

use OCA\LargeFilePreviews\AppInfo\Application;
use OCA\LargeFilePreviews\Config\ConfigLexicon;
use OCA\LargeFilePreviews\Config\SupportedTypes;
use OCA\LargeFilePreviews\Config\TypeRule;
use OCA\LargeFilePreviews\Preview\LargeFileProvider;
use OCA\LargeFilePreviews\Renderer\VipsRenderer;
use OCA\LargeFilePreviews\Service\BinaryLocator;
use OCA\LargeFilePreviews\Service\PrerequisitesService;
use OCA\LargeFilePreviews\SetupCheck\RendererCheck;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IPreview;
use OCP\IStreamImage;
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

//  constants

	/** A PNG of 2 x 2 pixels. */
	private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAC0lEQVQImWNgQAYAAA4AAbGa6gYAAAAASUVORK5CYII=';


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		if ( Server::get( PrerequisitesService::class )->blocking() )
		{
			self::markTestSkipped( 'Something the app needs is missing here; see occ large-file-previews:check.' );
		}

		// Documents ship off; these tests render them, as an administrator
		// who switched them on would have them rendered.
		foreach ( SupportedTypes::DOCUMENT_TYPES as $mime )
		{
			$this->settings()->setMimeRule( $mime, new TypeRule( true ) );
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

	/**
	 * A page that is one 300 dpi scan, 417 pixels wide, is not enlarged to
	 * the 1024 asked for.
	 */
	public function testAScanIsRenderedAtItsOwnSize(): void
	{
		$file  = $this->file( 'scan.pdf', PdfFixture::scan( 100, 50, 417, 208 ) );
		$image = Server::get( LargeFileProvider::class )->getThumbnail( $file, 1024, 1024 );

		self::assertNotNull( $image );
		self::assertEqualsWithDelta( 417, $image->width(), 1 );
	}

	/**
	 * The same page drawn rather than scanned fills the box.
	 */
	public function testADrawnPageFillsTheBox(): void
	{
		$image = Server::get( LargeFileProvider::class )->getThumbnail( $this->file( 'drawn.pdf', PdfFixture::bytes( 100, 50 ) ), 1024, 1024 );

		self::assertNotNull( $image );
		self::assertSame( 1024, $image->width() );
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

	/**
	 * Streamed, the largest preview Nextcloud stores is the render, byte for
	 * byte; with the stream off, it is Nextcloud's re-encoding of it.
	 */
	public function testTheLargestPreviewIsStoredAsRendered(): void
	{
		$system   = Server::get( IConfig::class );
		$maxX     = $system->getSystemValueInt( 'preview_max_x', 4096 );
		$maxY     = $system->getSystemValueInt( 'preview_max_y', 4096 );
		$file     = $this->file( 'stream.pdf', PdfFixture::bytes() );
		$rendered = Server::get( LargeFileProvider::class )->getThumbnail( $file, $maxX, $maxY );

		self::assertInstanceOf( IStreamImage::class, $rendered );

		$render = $rendered->data();

		self::assertSame( $render, Server::get( IPreview::class )->getPreview( $file, -1, -1 )->getContent() );

		$this->settings()->setScalar( ConfigLexicon::PREVIEW_STREAM, false );
		$decoded = Server::get( IPreview::class )->getPreview( $this->file( 'decoded.pdf', PdfFixture::bytes() ), -1, -1 )->getContent();

		self::assertNotSame( $render, $decoded );
		self::assertGreaterThan( 0, strlen( $decoded ) );
	}

	public function testOutsideTheSizeWindowItIsDeclined(): void
	{
		$this->settings()->setExtensionRule( 'pdf', new TypeRule( null, 1 ) );

		self::assertFalse( Server::get( LargeFileProvider::class )->isAvailable( $this->file( 'small.pdf', PdfFixture::bytes() ) ) );
	}

	/**
	 * Switched on, an image goes to libvips; where libvips is not there, the
	 * render fails and is recorded, and Nextcloud's next provider is tried.
	 */
	public function testAnImageWithoutLibvipsIsAFailureOnRecord(): void
	{
		if ( Server::get( BinaryLocator::class )->locate( VipsRenderer::PROGRAM )->isFound() )
		{
			self::markTestSkipped( 'libvips is installed here; ImageRenderTest renders images.' );
		}

		// From 0 MB: an image type starts at 50 by default.
		$this->settings()->setMimeRule( 'image/png', new TypeRule( true, 0 ) );
		$file     = $this->file( 'a.png', (string) base64_decode( self::PNG ) );
		$provider = Server::get( LargeFileProvider::class );

		self::assertTrue( $provider->isAvailable( $file ) );
		self::assertNull( $provider->getThumbnail( $file, 256, 256 ) );
		self::assertSame( 'program_missing', $this->appConfig()->getValueArray( Application::APP_ID, ConfigLexicon::LAST_FAILURE )['outcome'] ?? null );
	}

	/**
	 * Nextcloud stores the preview in the type it is given: JPEG as its
	 * preview_format reads by default, PNG where the app is told so, and
	 * JPEG for a WebP of a PDF where libvips is not there.
	 */
	public function testThePreviewIsStoredInTheFormatAsked(): void
	{
		$preview = Server::get( IPreview::class );

		self::assertSame( 'image/jpeg', $preview->getPreview( $this->file( 'jpeg.pdf', PdfFixture::bytes() ), 256, 256 )->getMimeType() );

		$this->settings()->setScalar( ConfigLexicon::PREVIEW_FORMAT, 'png' );

		self::assertSame( 'image/png', $preview->getPreview( $this->file( 'png.pdf', PdfFixture::bytes() ), 256, 256 )->getMimeType() );

		$this->settings()->setScalar( ConfigLexicon::PREVIEW_FORMAT, 'webp' );

		self::assertSame(
			Server::get( BinaryLocator::class )->locate( VipsRenderer::CONVERTER )->isFound() ? 'image/webp' : 'image/jpeg',
			$preview->getPreview( $this->file( 'webp.pdf', PdfFixture::bytes() ), 256, 256 )->getMimeType(),
		);
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

	/**
	 * A file that failed is left to the other providers for
	 * failure_retry_minutes, where Nextcloud has a memory cache to keep
	 * that in; without one, every request tries it again.
	 */
	public function testABrokenFileIsNotTriedAgainAtOnce(): void
	{
		$caches = Server::get( ICacheFactory::class );

		if ( ! $caches->isAvailable() && ! $caches->isLocalCacheAvailable() )
		{
			self::markTestSkipped( 'No memory cache here, so no failure is remembered.' );
		}

		$file     = $this->file( 'broken-again.pdf', "%PDF-1.4\nnot a pdf at all\n" );
		$provider = Server::get( LargeFileProvider::class );

		self::assertTrue( $provider->isAvailable( $file ) );
		self::assertNull( $provider->getThumbnail( $file, 256, 256 ) );
		self::assertFalse( $provider->isAvailable( $file ) );
	}

	public function testTheSetupCheckIsGreen(): void
	{
		self::assertSame( SetupResult::SUCCESS, Server::get( RendererCheck::class )->run()->getSeverity() );
	}
}
