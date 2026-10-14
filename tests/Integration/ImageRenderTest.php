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
use OCA\LargeFilePreviews\Renderer\VipsRenderer;
use OCA\LargeFilePreviews\Service\BinaryLocator;
use OCA\LargeFilePreviews\Service\ScratchDirectory;
use OCP\IImage;
use OCP\IPreview;
use OCP\Server;

/**
 * Real renders by libvips: each common format, at its own size where that
 * is smaller, the untrusted decoders' block, a JPEG's background, and a TIFF
 * in one strip that runs out of memory and is re-striped. Needs libvips and
 * tiffcp on the server: the CI job's libvips-tools and libtiff-tools.
 * Skipped where libvips is not there, as in a harness instance without it.
 */
class ImageRenderTest
    extends
    ServerTestCase
{

//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		if ( ! Server::get( BinaryLocator::class )->locate( VipsRenderer::PROGRAM )->isFound() )
		{
			self::markTestSkipped( 'libvips is not installed here.' );
		}
	}


//  other non-static methods

	/**
	 * GD writes each: PNG, JPEG, WebP and GIF, 600 x 300, into a box of 256.
	 */
	public function testEachCommonFormatIsRendered(): void
	{
		$formats = [
			'image/png'  => [ 'png', static fn( \GdImage $image ): bool => imagepng( $image ) ],
			'image/jpeg' => [ 'jpg', static fn( \GdImage $image ): bool => imagejpeg( $image ) ],
			'image/webp' => [ 'webp', static fn( \GdImage $image ): bool => imagewebp( $image ) ],
			'image/gif'  => [ 'gif', static fn( \GdImage $image ): bool => imagegif( $image ) ],
		];

		foreach ( $formats as $mime => [ $extension, $write ] )
		{
			$this->settings()->setMimeRule( $mime, new TypeRule( true, 0 ) );

			$image = Server::get( LargeFileProvider::class )->getThumbnail( $this->file( 'wide.' . $extension, self::gd( 600, 300, $write ) ), 256, 256 );

			self::assertNotNull( $image, $mime . ': ' . json_encode( $this->appConfig()->getValueArray( Application::APP_ID, ConfigLexicon::LAST_FAILURE ) ) );
			self::assertSame( 256, $image->width(), $mime );
			self::assertSame( 128, $image->height(), $mime );
		}
	}

	/**
	 * The file reaches libvips as a descriptor, so a name libvips would
	 * misread - load options in brackets, a comma, "out of memory" - is
	 * rendered as any other.
	 */
	public function testANameIsNeverReadByLibvips(): void
	{
		$this->settings()->setMimeRule( 'image/png', new TypeRule( true, 0 ) );

		$image = Server::get( LargeFileProvider::class )->getThumbnail(
			$this->file( 'out of memory, 1x1 uchar, 1 band, b-w, tiffload [page=9].png', self::gd( 300, 150, static fn( \GdImage $image ): bool => imagepng( $image ) ) ),
			128,
			128,
		);

		self::assertNotNull( $image, json_encode( $this->appConfig()->getValueArray( Application::APP_ID, ConfigLexicon::LAST_FAILURE ) ) ?: '' );
		self::assertSame( 128, $image->width() );
	}

	/**
	 * An image smaller than the preview keeps its own size.
	 */
	public function testASmallImageIsNotEnlarged(): void
	{
		$this->settings()->setMimeRule( 'image/png', new TypeRule( true, 0 ) );

		$image = Server::get( LargeFileProvider::class )->getThumbnail(
			$this->file( 'small.png', self::gd( 120, 80, static fn( \GdImage $image ): bool => imagepng( $image ) ) ),
			1024,
			1024,
		);

		self::assertNotNull( $image );
		self::assertSame( 120, $image->width() );
	}

	/**
	 * JPEG 2000's decoder is untrusted: blocked, the type goes to the next
	 * provider; allowed in system config, libvips reads it.
	 */
	public function testAnUntrustedDecoderIsBlockedUntilAllowed(): void
	{
		$this->settings()->setMimeRule( 'image/jp2', new TypeRule( true, 0 ) );
		$file     = $this->file( 'small.jp2', self::made( '.jp2', [ 'black', '', '64', '32', '--bands', '3' ] ) );
		$provider = Server::get( LargeFileProvider::class );

		self::assertSame( 'image/jp2', $file->getMimetype() );
		self::assertFalse( $provider->isAvailable( $file ) );

		$this->settings()->setUntrustedAllowed( true );

		self::assertTrue( $provider->isAvailable( $file ) );
		self::assertSame( 64, $provider->getThumbnail( $file, 256, 256 )?->width() );
	}

	/**
	 * A JPEG of a transparent image is laid on the background set; Nextcloud
	 * stores it as a JPEG.
	 */
	public function testAJpegOfATransparentImageHasTheBackground(): void
	{
		$this->settings()->setMimeRule( 'image/png', new TypeRule( true, 0 ) );
		$this->settings()->setScalar( ConfigLexicon::PREVIEW_FORMAT, 'jpeg' );

		$transparent = static function( \GdImage $image ): bool
		{
			// Written, not blended: blended, transparency over black is black.
			imagealphablending( $image, false );
			imagesavealpha( $image, true );
			imagefill( $image, 0, 0, (int) imagecolorallocatealpha( $image, 0, 0, 0, 127 ) );

			return imagepng( $image );
		};

		// A JPEG may round a channel by a step or two.
		foreach ( self::corner( Server::get( LargeFileProvider::class )->getThumbnail( $this->file( 'white.png', self::gd( 64, 64, $transparent ) ), 64, 64 ) ) as $channel )
		{
			self::assertGreaterThanOrEqual( 250, $channel );
		}

		$this->settings()->setScalar( ConfigLexicon::PREVIEW_BACKGROUND, 'black' );

		foreach ( self::corner( Server::get( LargeFileProvider::class )->getThumbnail( $this->file( 'black.png', self::gd( 64, 64, $transparent ) ), 64, 64 ) ) as $channel )
		{
			self::assertLessThanOrEqual( 5, $channel );
		}

		self::assertSame( 'image/jpeg', Server::get( IPreview::class )->getPreview( $this->file( 'stored.png', self::gd( 64, 64, $transparent ) ), 64, 64 )->getMimeType() );
	}

	/**
	 * Noise, 6000 x 6000 floats in one LZW strip, 151 MB: under a 256 MB
	 * cap libvips runs out of memory on it, tiffcp, which holds the
	 * compressed strip, does not, and libvips reads the copy. Measured so
	 * with libvips 8.15 and libtiff 4.5.
	 */
	public function testATiffInOneStripIsRestripedAfterRunningOutOfMemory(): void
	{
		if ( ! Server::get( BinaryLocator::class )->locate( VipsRenderer::RESTRIPPER )->isFound() )
		{
			self::markTestSkipped( 'tiffcp is not installed here.' );
		}

		$this->settings()->setMimeRule( 'image/tiff', new TypeRule( true, 0 ) );
		$this->settings()->setScalar( ConfigLexicon::VIPS_PRLIMIT_AS_MB, 256 );

		$tiff  = self::made( '.tif', [ 'gaussnoise', '[compression=lzw,tile-height=6000]', '6000', '6000', '--mean', '128', '--sigma', '30' ] );
		$image = Server::get( LargeFileProvider::class )->getThumbnail( $this->file( 'one-strip.tif', $tiff ), 1024, 1024 );

		self::assertNotNull( $image, json_encode( $this->appConfig()->getValueArray( Application::APP_ID, ConfigLexicon::LAST_FAILURE ) ) ?: '' );
		self::assertSame( 1024, $image->width() );
		self::assertSame( [], glob( Server::get( ScratchDirectory::class )->path() . '/lfp-restrip-*' ) ?: [] );
	}


//  static methods

	/**
	 * An image GD draws, a red square on its own background, as the
	 * writer given writes it.
	 *
	 * @param \Closure(\GdImage): bool $write
	 */
	private static function gd(
		int      $width,
		int      $height,
		\Closure $write,
	): string
	{
		$image = imagecreatetruecolor( $width, $height );
		imagefilledrectangle( $image, (int) ( $width / 4 ), (int) ( $height / 4 ), (int) ( $width / 2 ), (int) ( $height / 2 ), (int) imagecolorallocate( $image, 255, 0, 0 ) );

		ob_start();
		$write( $image );

		return (string) ob_get_clean();
	}

	/**
	 * The red, green and blue of the top left pixel.
	 *
	 * @return list<int>
	 */
	private static function corner( ?IImage $image ): array
	{
		self::assertNotNull( $image );

		$resource = $image->resource();
		self::assertInstanceOf( \GdImage::class, $resource );
		$color = imagecolorsforindex( $resource, (int) imagecolorat( $resource, 1, 1 ) );

		return [ $color['red'], $color['green'], $color['blue'] ];
	}

	/**
	 * An image vips makes in one step, `vips <operation> <file><options>
	 * <arguments>`, read from a temporary file removed afterwards.
	 *
	 * @param list<string> $command the operation, the save options, then
	 *                              the operation's arguments
	 */
	private static function made(
		string $extension,
		array  $command,
	): string
	{
		$file = sys_get_temp_dir() . '/lfp-made-' . bin2hex( random_bytes( 4 ) ) . $extension;

		[ $operation, $options ] = $command;

		try
		{
			$process = proc_open(
				[ 'vips', $operation, $file . $options, ...array_slice( $command, 2 ) ],
				[ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
				$pipes,
			);
			self::assertIsResource( $process );
			$error = stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			self::assertSame( 0, proc_close( $process ), 'vips ' . $operation . ': ' . $error );

			return (string) file_get_contents( $file );
		}
		finally
		{
			@unlink( $file );
		}
	}
}
