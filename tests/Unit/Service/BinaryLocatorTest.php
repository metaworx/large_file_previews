<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Unit\Service;

use OCA\LargeFilePreviews\Service\BinaryLocation;
use OCA\LargeFilePreviews\Service\BinaryLocator;
use OCA\LargeFilePreviews\Tests\Unit\MemoryCache;
use OCP\IBinaryFinder;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BinaryLocatorTest
    extends
    TestCase
{
	use MemoryCache;


//  other non-static methods

	/**
	 * Found once, then from the cache; refresh() asks again.
	 */
	public function testWhatTheFinderAnswersIsKeptUntilRefreshed(): void
	{
		$config = $this->createMock( IConfig::class );
		$config->method( 'getSystemValue' )
		       ->willReturn( [] )
		;
		$finder = $this->createMock( IBinaryFinder::class );
		$finder->expects( self::exactly( 2 ) )
		       ->method( 'findBinaryPath' )
		       ->willReturn( '/usr/bin/pdftoppm' )
		;

		$cache = $this->detectionCache();
		$first = new BinaryLocator( $config, $finder, $cache, $this->createMock( LoggerInterface::class ) );
		self::assertSame( '/usr/bin/pdftoppm', $first->locate( 'pdftoppm' )->path );

		// Another request: from the cache, not the finder.
		$second = new BinaryLocator( $config, $finder, $cache, $this->createMock( LoggerInterface::class ) );
		self::assertSame( '/usr/bin/pdftoppm', $second->locate( 'pdftoppm' )->path );
		self::assertSame( [ 'path.pdftoppm' => '/usr/bin/pdftoppm' ], $this->cacheValues );

		$second->refresh();
		self::assertSame( '/usr/bin/pdftoppm', $second->locate( 'pdftoppm' )->path );
	}

	/**
	 * "Missing" is kept too, so a missing program is not looked for on
	 * every image on the instance.
	 */
	public function testAMissingProgramIsKeptAsMissing(): void
	{
		$this->locator( [], [] )->locate( 'choom' );

		self::assertSame( '', $this->cacheValues['path.choom'] ?? null );
	}

	public function testTheFinderAnswersWithoutAnOverride(): void
	{
		$location = $this->locator( [], [ 'pdftoppm' => '/usr/bin/pdftoppm' ] )->locate( 'pdftoppm' );

		self::assertSame( '/usr/bin/pdftoppm', $location->path );
		self::assertSame( BinaryLocation::SOURCE_FINDER, $location->source );
		self::assertTrue( $location->isFound() );
	}

	public function testAProgramTheFinderLacksIsMissing(): void
	{
		$location = $this->locator( [], [] )->locate( 'choom' );

		self::assertFalse( $location->isFound() );
		self::assertNull( $location->problem );
	}

	public function testAValidOverrideWins(): void
	{
		$location = $this->locator( [ 'timeout' => PHP_BINARY ], [ 'timeout' => '/usr/bin/timeout' ] )->locate( 'timeout' );

		self::assertSame( PHP_BINARY, $location->path );
		self::assertSame( BinaryLocation::SOURCE_OVERRIDE, $location->source );
	}

	/**
	 * The administrator asked for that binary: a broken override does not
	 * fall back to the finder's, so a limit needing it fails closed.
	 */
	public function testABrokenOverrideLeavesTheProgramMissing(): void
	{
		$cases = [
			'relative'        => [ 'bin/pdftoppm', 'the override is not an absolute path' ],
			'missing'         => [ '/nonexistent/pdftoppm', 'the override does not exist' ],
			'not executable'  => [ __FILE__, 'the override is not executable' ],
			'not a string'    => [ [ '/usr/bin/pdftoppm' ], 'the override is not a path' ],
		];

		foreach ( $cases as $label => [ $override, $problem ] )
		{
			$location = $this->locator( [ 'pdftoppm' => $override ], [ 'pdftoppm' => '/usr/bin/pdftoppm' ] )->locate( 'pdftoppm' );

			self::assertFalse( $location->isFound(), $label );
			self::assertSame( BinaryLocation::SOURCE_OVERRIDE, $location->source, $label );
			self::assertSame( $problem, $location->problem, $label );
		}
	}

	public function testLocateAllCoversEveryProgram(): void
	{
		self::assertSame( BinaryLocator::PROGRAMS, array_keys( $this->locator( [], [] )->locateAll() ) );
	}

	public function testProblemsNameUnknownProgramsAndNonPaths(): void
	{
		$locator = $this->locator( [ 'bash' => '/bin/bash', 'prlimit' => 5 ], [] );

		self::assertSame(
			[
				'large_file_previews.binaries.bash: not a program this app runs; ignored',
				'large_file_previews.binaries.prlimit: not a path',
			],
			$locator->problems(),
		);
	}

	public function testAnOverrideSettingThatIsNoMapIsReported(): void
	{
		self::assertSame(
			[ 'large_file_previews.binaries is not a map from program to path; ignored' ],
			$this->locator( '/usr/bin/pdftoppm', [] )->problems(),
		);
	}

	/**
	 * @param array<array-key, mixed>|string $overrides
	 * @param array<string, string>          $found
	 */
	private function locator(
		array|string $overrides,
		array        $found,
	): BinaryLocator
	{
		$config = $this->createMock( IConfig::class );
		$config->method( 'getSystemValue' )
		       ->with( BinaryLocator::SYSTEM_KEY )
		       ->willReturn( $overrides )
		;

		$finder = $this->createMock( IBinaryFinder::class );
		$finder->method( 'findBinaryPath' )
		       ->willReturnCallback(
			       static fn(
				       string $program,
			       ): string|false => $found[ $program ] ?? false,
		       )
		;

		return new BinaryLocator( $config, $finder, $this->detectionCache(), $this->createMock( LoggerInterface::class ) );
	}
}
