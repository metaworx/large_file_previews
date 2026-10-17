<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Unit\Preview;

use BadMethodCallException;
use OCA\LargeFilePreviews\Preview\RenderedPreview;
use PHPUnit\Framework\TestCase;

class RenderedPreviewTest
    extends
    TestCase
{

//  private properties

	private string $path = '';


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();
		$this->path = (string) tempnam( sys_get_temp_dir(), 'lfp-rendered-' );
	}


//  other non-static methods

	protected function tearDown(): void
	{
		@unlink( $this->path );

		parent::tearDown();
	}

	/**
	 * Its type and size as the file has them, and its bytes as they are,
	 * still readable once the file is unlinked: the folder it was rendered
	 * in goes before Nextcloud reads it.
	 */
	public function testAJpegIsOpenedAsItIsAndOutlivesItsFile(): void
	{
		$gd = imagecreatetruecolor( 30, 20 );
		imagejpeg( $gd, $this->path, 90 );
		$bytes = (string) file_get_contents( $this->path );

		$preview = RenderedPreview::open( $this->path );
		unlink( $this->path );

		self::assertInstanceOf( RenderedPreview::class, $preview );
		self::assertTrue( $preview->valid() );
		self::assertSame( [ 'image/jpeg', 30, 20 ], [ $preview->dataMimeType(), $preview->width(), $preview->height() ] );
		self::assertSame( $bytes, $preview->data() );
		self::assertSame( $bytes, stream_get_contents( $preview->resource() ) );
	}

	public function testWhatIsNotAnImageOfARendererIsNotOpened(): void
	{
		file_put_contents( $this->path, 'not an image' );
		self::assertNull( RenderedPreview::open( $this->path ) );

		$gd = imagecreatetruecolor( 4, 4 );
		imagegif( $gd, $this->path );
		self::assertNull( RenderedPreview::open( $this->path ) );

		self::assertNull( RenderedPreview::open( $this->path . '.missing' ) );
	}

	public function testItIsNotAnImageToChange(): void
	{
		imagepng( imagecreatetruecolor( 4, 4 ), $this->path );
		$preview = RenderedPreview::open( $this->path );

		self::assertInstanceOf( RenderedPreview::class, $preview );
		$this->expectException( BadMethodCallException::class );

		$preview->resize( 2 );
	}
}
