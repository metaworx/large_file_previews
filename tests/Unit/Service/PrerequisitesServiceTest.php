<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Unit\Service;

use OCA\LargeFilePreviews\Config\ConfigLexicon;
use OCA\LargeFilePreviews\Renderer\Unsupported;
use OCA\LargeFilePreviews\Service\BinaryLocator;
use OCA\LargeFilePreviews\Service\CommandRunner;
use OCA\LargeFilePreviews\Service\DetectionCache;
use OCA\LargeFilePreviews\Service\Outcome;
use OCA\LargeFilePreviews\Service\Platform;
use OCA\LargeFilePreviews\Service\Prerequisite;
use OCA\LargeFilePreviews\Service\PrerequisitesService;
use OCA\LargeFilePreviews\Service\ProgramVersions;
use OCA\LargeFilePreviews\Service\RenderOutcome;
use OCA\LargeFilePreviews\Service\Sandbox;
use OCA\LargeFilePreviews\Tests\Unit\MemoryAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;

class PrerequisitesServiceTest
    extends
    TestCase
{
	use MemoryAppConfig;


//  other non-static methods

	public function testEverythingThereIsReady(): void
	{
		$service = $this->service( [ 'pdftoppm', 'pdfinfo', 'pdfimages', 'vipsthumbnail', 'vipsheader', 'vips', 'tiffcp', 'timeout', 'prlimit', 'choom' ] );
		$items   = $service->check();

		self::assertSame(
			[ 'proc_open', 'proc_get_status', 'proc_close', '/dev/fd', 'temporary directory', 'gd', 'sysvsem', 'pdftoppm', 'pdfinfo', 'pdfimages', 'vipsthumbnail', 'vipsheader', 'vips', 'tiffcp', 'timeout', 'prlimit', 'choom', 'bwrap' ],
			array_map(
				static fn(
					Prerequisite $item,
				): string => $item->name,
				$items,
			),
		);
		self::assertSame( '/usr/bin/pdftoppm (finder)', self::item( $items, 'pdftoppm' )->detail );

		if ( extension_loaded( 'gd' ) )
		{
			self::assertFalse( $service->blocking( $items ) );
		}
	}

	public function testAMissingRendererBlocks(): void
	{
		$items = $this->service( [ 'timeout', 'prlimit', 'choom' ] )->check();

		self::assertSame( Prerequisite::MISSING, self::item( $items, 'pdftoppm' )->status );
		self::assertTrue( self::item( $items, 'pdftoppm' )->isBlocking() );
	}

	/**
	 * Without them a scan is enlarged to the full box: worth saying, no
	 * reason to stop rendering.
	 */
	public function testTheScanProbesAreOptional(): void
	{
		$items = $this->service( [ 'pdftoppm', 'timeout', 'prlimit', 'choom' ] )->check();

		self::assertSame( Prerequisite::OPTIONAL, self::item( $items, 'pdfinfo' )->status );
		self::assertSame( Prerequisite::OPTIONAL, self::item( $items, 'pdfimages' )->status );
		self::assertSame( 'rendering a scanned page at its own resolution', self::item( $items, 'pdfimages' )->neededFor );
	}

	/**
	 * A limit switched off does not need its program; switched on, it
	 * does, since the run fails closed.
	 */
	public function testALimitsProgramIsOptionalOnlyWhenItIsOff(): void
	{
		$items = $this->service( [ 'pdftoppm', 'timeout', 'prlimit' ] )->check();
		self::assertSame( Prerequisite::MISSING, self::item( $items, 'choom' )->status );

		$this->appValues[ ConfigLexicon::CHOOM_ENABLED ] = false;
		$items                                           = $this->service( [ 'pdftoppm', 'timeout', 'prlimit' ] )->check();

		self::assertSame( Prerequisite::OPTIONAL, self::item( $items, 'choom' )->status );
		self::assertStringEndsWith( 'switched off', self::item( $items, 'choom' )->neededFor );
		self::assertFalse( self::item( $items, 'choom' )->isBlocking() );
	}

	/**
	 * bwrap found is not enough: it must start a sandbox here. Switched off,
	 * the page still says whether it could; the setup check, which asks no
	 * versions, does not start one.
	 */
	public function testABwrapThatCannotStartASandboxIsOptionalWhileOff(): void
	{
		$this->cacheValues[ Sandbox::probeKey( '/usr/bin/bwrap' ) ] = 'setting up uid map: Permission denied';
		$programs                                                   = [ 'pdftoppm', 'timeout', 'prlimit', 'choom', 'bwrap' ];

		$asked = self::item( $this->service( $programs, $this->bareRunner() )->check( true ), 'bwrap' );
		self::assertSame( Prerequisite::OPTIONAL, $asked->status );
		self::assertStringContainsString( 'cannot start a sandbox here: setting up uid map: Permission denied', $asked->detail );
		self::assertStringEndsWith( 'switched off', $asked->neededFor );

		self::assertSame( Prerequisite::OK, self::item( $this->service( $programs )->check(), 'bwrap' )->status );
	}

	/**
	 * Switched on, a bwrap that cannot start a sandbox blocks, since every
	 * render would fail; one that can is ready.
	 */
	public function testWhileOnTheSandboxMustStart(): void
	{
		$this->appValues[ ConfigLexicon::BWRAP_ENABLED ]            = true;
		$this->cacheValues[ Sandbox::probeKey( '/usr/bin/bwrap' ) ] = 'No permissions to create new namespace';
		$programs                                                   = [ 'pdftoppm', 'timeout', 'prlimit', 'choom', 'bwrap' ];

		$refused = self::item( $this->service( $programs )->check(), 'bwrap' );
		self::assertSame( Prerequisite::MISSING, $refused->status );
		self::assertTrue( $refused->isBlocking() );

		$this->cacheValues[ Sandbox::probeKey( '/usr/bin/bwrap' ) ] = '';
		self::assertSame( Prerequisite::OK, self::item( $this->service( $programs )->check(), 'bwrap' )->status );
		self::assertSame( Prerequisite::MISSING, self::item( $this->service( [ 'pdftoppm', 'timeout', 'prlimit', 'choom' ] )->check(), 'bwrap' )->status );
	}

	/**
	 * A version is asked for once and kept by path: the page asks often,
	 * and a program started for every page load is one too many.
	 */
	public function testVersionsAreKeptByPath(): void
	{
		$runner = $this->createMock( CommandRunner::class );
		$runner->expects( self::exactly( 6 ) )
		       ->method( 'runBare' )
		       ->willReturn( new RenderOutcome( Outcome::SUCCEEDED, 'exit status 0', 0, null, 'v1' ) )
		;

		$programs = [ 'pdftoppm', 'pdfinfo', 'pdfimages', 'timeout', 'prlimit', 'choom' ];
		$this->service( $programs, $runner )->check( true );
		$this->service( $programs, $runner )->check( true );

		self::assertSame( 'v1', $this->cacheValues['version./usr/bin/pdftoppm'] ?? null );
	}

	/**
	 * Without a memory cache, the page's check() and programList() still
	 * ask each program once in the request.
	 */
	public function testEachProgramIsAskedOnceARequestWithoutACache(): void
	{
		$runner = $this->createMock( CommandRunner::class );
		$runner->expects( self::exactly( 4 ) )
		       ->method( 'runBare' )
		       ->willReturn( new RenderOutcome( Outcome::SUCCEEDED, 'exit status 0', 0, null, 'v1' ) )
		;

		$service = $this->service( [ 'pdftoppm', 'timeout', 'prlimit', 'choom' ], $runner, cached: false );
		$service->check( true );
		$service->programList();
		$service->check( true );
	}

	/**
	 * A version not said, as where its run failed, is kept a minute only:
	 * libvips' renders hang on it.
	 */
	public function testAVersionNotSaidIsAskedAgainSoon(): void
	{
		$runner = $this->createMock( CommandRunner::class );
		$runner->method( 'runBare' )
		       ->willReturnCallback(
			       static fn(
				       string $program,
			       ): RenderOutcome => $program === 'pdftoppm'
				       ? new RenderOutcome( Outcome::SUCCEEDED, 'exit status 0', 0, null, 'v1' )
				       : new RenderOutcome( Outcome::LIMIT_MISSING, 'timeout is switched on but not found' ),
		       )
		;

		$this->service( [ 'pdftoppm', 'timeout', 'prlimit', 'choom' ], $runner )->check( true );

		self::assertSame( DetectionCache::TTL, $this->cacheTtls['version./usr/bin/pdftoppm'] ?? null );
		self::assertSame( ProgramVersions::UNKNOWN_TTL, $this->cacheTtls['version./usr/bin/choom'] ?? null );
	}

	public function testVersionsAreTheFirstLineEachProgramPrints(): void
	{
		$runner = $this->createMock( CommandRunner::class );
		$runner->method( 'runBare' )
		       ->willReturnCallback(
			       static fn(
				       string $program,
				       array  $arguments,
			       ): RenderOutcome => new RenderOutcome(
				       Outcome::SUCCEEDED,
				       'exit status 0',
				       0,
				       null,
				       $program === 'pdftoppm' && $arguments === [ '-v' ]
					       ? "pdftoppm version 24.02.0\nCopyright"
					       : $program . ' 1.0',
			       ),
		       )
		;

		$items = $this->service( [ 'pdftoppm', 'timeout', 'prlimit', 'choom' ], $runner )->check( true );

		self::assertSame( '/usr/bin/pdftoppm (finder), pdftoppm version 24.02.0', self::item( $items, 'pdftoppm' )->detail );
		self::assertSame( '/usr/bin/choom (finder), choom 1.0', self::item( $items, 'choom' )->detail );
	}

	/**
	 * Every program, for the settings page: path, how it was found, and
	 * the version where it is there.
	 */
	public function testTheProgramList(): void
	{
		$runner = $this->createMock( CommandRunner::class );
		$runner->method( 'runBare' )
		       ->willReturn( new RenderOutcome( Outcome::SUCCEEDED, 'exit status 0', 0, null, "pdftoppm version 24.02.0\nCopyright" ) )
		;

		$list = $this->service( [ 'pdftoppm' ], $runner )->programList();

		// The one list of programs: the page's list and the check's are it.
		self::assertSame( array_keys( BinaryLocator::PROGRAMS ), array_column( $list, 'name' ) );
		self::assertSame(
			array_keys( BinaryLocator::PROGRAMS ),
			array_values(
				array_map(
					static fn(
						Prerequisite $item,
					): string => $item->name,
					array_filter(
						$this->service( [ 'pdftoppm' ], $runner )->check(),
						static fn(
							Prerequisite $item,
						): bool => $item->group === Prerequisite::GROUP_PROGRAM,
					),
				),
			),
		);
		self::assertSame(
			[
				'name'    => 'pdftoppm',
				'path'    => '/usr/bin/pdftoppm',
				'source'  => 'finder',
				'problem' => null,
				'version' => 'pdftoppm version 24.02.0',
				'install' => [ 'command' => 'apt install poppler-utils', 'note' => null ],
			],
			$list[0],
		);
		self::assertSame( 'vips', $list[5]['name'] );
		self::assertNull( $list[5]['path'] );
		self::assertNull( $list[5]['version'] );
		self::assertSame( [ 'command' => 'apt install libvips-tools', 'note' => null ], $list[5]['install'] );
	}

	/**
	 * vipsthumbnail is optional while every image type is off, and needed,
	 * with what installs it, once one is on, by its MIME type or by an
	 * extension.
	 */
	public function testVipsthumbnailIsNeededWhileImagesAreOn(): void
	{
		$programs = [ 'pdftoppm', 'timeout', 'prlimit', 'choom' ];
		$item     = self::item( $this->service( $programs )->check(), 'vipsthumbnail' );

		self::assertSame( Prerequisite::OPTIONAL, $item->status );
		self::assertSame( 'rendering images, switched off', $item->neededFor );
		self::assertSame( [ 'command' => 'apt install libvips-tools', 'note' => null ], $item->install );

		$this->appValues = [ ConfigLexicon::MIMETYPES => [ 'image/tiff' => [ 'enabled' => true ] ] ];
		$service         = $this->service( $programs );

		self::assertSame( Prerequisite::MISSING, self::item( $service->check(), 'vipsthumbnail' )->status );
		self::assertTrue( $service->blocking() );
		self::assertSame( Prerequisite::OPTIONAL, self::item( $service->check(), 'vipsheader' )->status );

		$this->appValues = [ ConfigLexicon::EXTENSIONS => [ 'tif' => [ 'enabled' => true ] ] ];

		self::assertSame( Prerequisite::MISSING, self::item( $this->service( $programs )->check(), 'vipsthumbnail' )->status );
		self::assertSame( 'Debian or Ubuntu', $this->service( $programs )->platformName() );
	}

	/**
	 * libvips before 8.13 cannot block untrusted decoders: the command
	 * says no image is rendered, unless they are allowed.
	 */
	public function testAnOldLibvipsIsNamed(): void
	{
		$runner = $this->createMock( CommandRunner::class );
		$runner->method( 'runBare' )
		       ->willReturnCallback(
			       static fn(
				       string $program,
			       ): RenderOutcome => new RenderOutcome( Outcome::SUCCEEDED, 'exit status 0', 0, null, $program === 'vipsthumbnail' ? 'vips-8.12.1' : 'v1' ),
		       )
		;
		$programs = [ 'pdftoppm', 'vipsthumbnail', 'timeout', 'prlimit', 'choom' ];

		self::assertStringEndsWith( 'vips-8.12.1; ' . Unsupported::UNBLOCKABLE_TEXT, self::item( $this->service( $programs, $runner )->check( true ), 'vipsthumbnail' )->detail );

		$this->systemValues = [ ConfigLexicon::ALLOW_UNTRUSTED_SYSTEM_KEY => true ];

		self::assertStringEndsWith( 'vips-8.12.1', self::item( $this->service( $programs, $runner )->check( true ), 'vipsthumbnail' )->detail );
	}

	public function testAnUnwritableTemporaryDirectoryBlocks(): void
	{
		$items = $this->service( [ 'pdftoppm', 'timeout', 'prlimit', 'choom' ], null, '/nonexistent' )->check();

		self::assertSame( Prerequisite::MISSING, self::item( $items, 'temporary directory' )->status );
	}

	/**
	 * @param list<string> $found
	 */
	private function service(
		array          $found,
		?CommandRunner $runner = null,
		string         $temp = '',
		bool           $cached = true,
	): PrerequisitesService
	{
		// Without a memory cache Nextcloud hands out one that keeps nothing.
		$forgetting = $this->createMock( ICacheFactory::class );
		$forgetting->method( 'createLocal' )
		           ->willReturn( $this->createMock( ICache::class ) )
		;
		$versions = $cached ? $this->detectionCache() : new DetectionCache( $forgetting );

		$tempManager = $this->createMock( ITempManager::class );
		$tempManager->method( 'getTempBaseDir' )
		            ->willReturn( $temp === '' ? sys_get_temp_dir() : $temp )
		;

		$binaries = $this->binaryLocator(
			static fn(
				string $program,
			): string|false => in_array( $program, $found, true ) ? '/usr/bin/' . $program : false,
		);

		return new PrerequisitesService(
			$this->settings(),
			$binaries,
			new ProgramVersions( $runner ?? $this->createMock( CommandRunner::class ), $versions ),
			$tempManager,
			new Platform( dirname( __DIR__, 2 ) . '/fixtures/os-release/ubuntu' ),
			new Sandbox( $binaries, $this->detectionCache() ),
		);
	}

	/**
	 * A runner every program answers `v1` to.
	 */
	private function bareRunner(): CommandRunner
	{
		$runner = $this->createMock( CommandRunner::class );
		$runner->method( 'runBare' )
		       ->willReturn( new RenderOutcome( Outcome::SUCCEEDED, 'exit status 0', 0, null, 'v1' ) )
		;

		return $runner;
	}


//  static methods

	/**
	 * @param list<Prerequisite> $items
	 */
	private static function item(
		array  $items,
		string $name,
	): Prerequisite
	{
		foreach ( $items as $item )
		{
			if ( $item->name === $name )
			{
				return $item;
			}
		}

		self::fail( 'No item ' . $name );
	}
}
