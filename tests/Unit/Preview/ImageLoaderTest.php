<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Unit\Preview;

use OCA\LargeFilePreviews\Preview\ImageLoader;
use PHPUnit\Framework\TestCase;

/**
 * Needs the server's image class, so runs inside a server only.
 */
class ImageLoaderTest
    extends
    TestCase
{

//  constants

	private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAC0lEQVQImWNgQAYAAA4AAbGa6gYAAAAASUVORK5CYII=';


//  private properties

	private string $file;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		if ( ! class_exists( \OC\Image::class ) )
		{
			self::markTestSkipped( 'No Nextcloud server here.' );
		}

		$this->file = (string) tempnam( sys_get_temp_dir(), 'lfp-image-' );
	}


//  other non-static methods

	protected function tearDown(): void
	{
		@unlink( $this->file );

		parent::tearDown();
	}

	public function testAPngLoads(): void
	{
		file_put_contents( $this->file, base64_decode( self::PNG ) );

		$image = ( new ImageLoader() )->load( $this->file );

		self::assertNotNull( $image );
		self::assertSame( 2, $image->width() );
	}

	public function testWhatIsNoImageIsNull(): void
	{
		file_put_contents( $this->file, '%PDF-1.4 not an image' );

		self::assertNull( ( new ImageLoader() )->load( $this->file ) );
	}

	public function testAnEmptyOrMissingFileIsNull(): void
	{
		self::assertNull( ( new ImageLoader() )->load( $this->file ) );
		self::assertNull( ( new ImageLoader() )->load( $this->file . '.gone' ) );
	}
}
