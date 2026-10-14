<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Unit\Renderer;

use OCA\LargeFilePreviews\Renderer\VipsLoaders;
use OCA\LargeFilePreviews\Service\BinaryLocator;
use OCA\LargeFilePreviews\Service\CommandRunner;
use OCA\LargeFilePreviews\Service\Outcome;
use OCA\LargeFilePreviews\Service\ProgramVersions;
use OCA\LargeFilePreviews\Service\RenderOutcome;
use OCA\LargeFilePreviews\Tests\Unit\MemoryCache;
use OCP\IBinaryFinder;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class VipsLoadersTest
    extends
    TestCase
{
	use MemoryCache;


//  constants

	/**
	 * Lines of `vips -l foreign` from libvips 8.15.1, shortened.
	 */
	private const LISTING
		 = <<<'TXT'
		      VipsForeignLoad (load), loaders, priority=0
		        VipsForeignLoadPpm (ppmload_base), load ppm base class (.pbm, .pgm, .ppm, .pfm, .pnm), priority=200, untrusted
		          VipsForeignLoadPpmFile (ppmload), load ppm from file (.pbm, .pgm, .ppm, .pfm, .pnm), priority=200, untrusted, is_a, get_flags, header, load
		          VipsForeignLoadPpmSource (ppmload_source), load ppm from source, priority=200, untrusted, is_a_source, get_flags, header, load
		        VipsForeignLoadJp2k (jp2kload_base), load JPEG2000 image, priority=0, untrusted
		          VipsForeignLoadJp2kFile (jp2kload), load JPEG2000 image (.j2k, .jp2, .jpt, .j2c, .jpc), priority=0, untrusted, is_a, get_flags, header, load
		          VipsForeignLoadTiffFile (tiffload), load tiff from file (.tif, .tiff), priority=50, is_a, get_flags, get_flags_filename, header, load
		          VipsForeignLoadHeifFile (heifload), load a HEIF image (.heic, .heif, .avif), priority=0, is_a, get_flags, header, load
		          VipsForeignLoadMagickFile (magickload), load file with ImageMagick, priority=-100, untrusted, is_a, get_flags, get_flags_filename, header
		      VipsForeignSave (save), savers, priority=0
		        VipsForeignSaveTiffFile (tiffsave), save image to tiff file (.tif, .tiff), priority=0, rgb
		TXT;


//  other non-static methods

	public function testTheListingParsed(): void
	{
		self::assertSame(
			[
				'ppmload'    => true,
				'jp2kload'   => true,
				'tiffload'   => false,
				'heifload'   => false,
				'magickload' => true,
			],
			VipsLoaders::parse( self::LISTING ),
		);
		self::assertSame( [], VipsLoaders::parse( 'vips: unknown action "-l"' ) );
	}

	/**
	 * Asked once, kept by the path of `vips`; the whole listing is read,
	 * not just its first 4 KB.
	 */
	public function testAskedOnceAndKept(): void
	{
		$runner = $this->createMock( CommandRunner::class );
		$runner->expects( self::once() )
		       ->method( 'runBare' )
		       ->with( 'vips', [ '-l', 'foreign' ], self::greaterThan( 65_536 ) )
		       ->willReturn( new RenderOutcome( Outcome::SUCCEEDED, 'exit status 0', 0, null, self::LISTING ) )
		;

		$loaders = $this->loaders( $runner, '/usr/bin/vips' )->loaders();

		self::assertSame( false, $loaders['tiffload'] ?? null );
		self::assertSame( '{"ppmload":true,"jp2kload":true,"tiffload":false,"heifload":false,"magickload":true}', $this->cacheValues['vips-loaders./usr/bin/vips'] ?? null );
		// Another request, the same cache: no second run.
		self::assertSame( $loaders, $this->loaders( $runner, '/usr/bin/vips' )->loaders() );
	}

	/**
	 * Nothing to tell from: no `vips`, or no answer, which is asked again.
	 */
	public function testNothingKnownWithoutAnAnswer(): void
	{
		$runner = $this->createMock( CommandRunner::class );
		$runner->expects( self::exactly( 2 ) )
		       ->method( 'runBare' )
		       ->willReturn( new RenderOutcome( Outcome::FAILED, 'exit status 1', 1 ) )
		;

		self::assertNull( $this->loaders( $runner, false )->loaders() );

		// Found again, as after a refresh.
		$this->cacheValues = [];

		self::assertNull( $this->loaders( $runner, '/usr/bin/vips' )->loaders() );
		self::assertNull( $this->loaders( $runner, '/usr/bin/vips' )->loaders() );
		self::assertArrayNotHasKey( 'vips-loaders./usr/bin/vips', $this->cacheValues );
	}

	/**
	 * libvips blocks untrusted decoders from 8.13 on; a version it does not
	 * say counts as one that cannot.
	 */
	public function testWhichVersionsBlock(): void
	{
		self::assertTrue( VipsLoaders::blocks( 'vips-8.15.1' ) );
		self::assertTrue( VipsLoaders::blocks( 'vips-8.13.0-Mon Jan  1 00:00:00 UTC 2024' ) );
		self::assertTrue( VipsLoaders::blocks( 'vips-9.0.0' ) );
		self::assertFalse( VipsLoaders::blocks( 'vips-8.12.1' ) );
		self::assertFalse( VipsLoaders::blocks( 'vips-8.9.2' ) );
		self::assertFalse( VipsLoaders::blocks( '' ) );
		self::assertFalse( VipsLoaders::blocks( 'vipsthumbnail: unknown option' ) );
	}

	/**
	 * From vipsthumbnail's version, asked once and kept by its path;
	 * nothing known where it is not there.
	 */
	public function testWhetherThisLibvipsCanBlock(): void
	{
		$runner = $this->createMock( CommandRunner::class );
		$runner->expects( self::once() )
		       ->method( 'runBare' )
		       ->with( 'vipsthumbnail', [ '--version' ] )
		       ->willReturn( new RenderOutcome( Outcome::SUCCEEDED, 'exit status 0', 0, null, "vips-8.12.1\n" ) )
		;

		self::assertFalse( $this->loaders( $runner, '/usr/bin/vipsthumbnail' )->canBlockUntrusted() );
		self::assertFalse( $this->loaders( $runner, '/usr/bin/vipsthumbnail' )->canBlockUntrusted() );
		self::assertSame( 'vips-8.12.1', $this->cacheValues['version./usr/bin/vipsthumbnail'] ?? null );

		$this->cacheValues = [ 'version./usr/bin/vipsthumbnail' => 'vips-8.15.1' ];

		self::assertTrue( $this->loaders( $runner, '/usr/bin/vipsthumbnail' )->canBlockUntrusted() );

		// Found again, as after a refresh, and not there.
		$this->cacheValues = [];

		self::assertNull( $this->loaders( $runner, false )->canBlockUntrusted() );
	}

	private function loaders(
		CommandRunner $runner,
		string|false  $vips,
	): VipsLoaders
	{
		$finder = $this->createMock( IBinaryFinder::class );
		$finder->method( 'findBinaryPath' )
		       ->willReturn( $vips )
		;

		$config = $this->createMock( IConfig::class );
		$config->method( 'getSystemValue' )
		       ->willReturn( [] )
		;

		$cache = $this->detectionCache();

		return new VipsLoaders(
			$runner,
			new BinaryLocator( $config, $finder, $cache, $this->createMock( LoggerInterface::class ) ),
			$cache,
			new ProgramVersions( $runner, $cache ),
		);
	}
}
