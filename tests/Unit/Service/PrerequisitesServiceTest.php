<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Unit\Service;

use OCA\LargeFilePreviews\Config\ConfigLexicon;
use OCA\LargeFilePreviews\Service\BinaryLocator;
use OCA\LargeFilePreviews\Service\CommandRunner;
use OCA\LargeFilePreviews\Service\ConfigurationService;
use OCA\LargeFilePreviews\Service\Outcome;
use OCA\LargeFilePreviews\Service\Prerequisite;
use OCA\LargeFilePreviews\Service\PrerequisitesService;
use OCA\LargeFilePreviews\Service\RenderOutcome;
use OCA\LargeFilePreviews\Tests\Unit\EnglishL10n;
use OCA\LargeFilePreviews\Tests\Unit\MemoryAppConfig;
use OCP\IBinaryFinder;
use OCP\IConfig;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PrerequisitesServiceTest
    extends
    TestCase
{
	use EnglishL10n;
	use MemoryAppConfig;


//  other non-static methods

	public function testEverythingThereIsReady(): void
	{
		$service = $this->service( [ 'pdftoppm', 'timeout', 'prlimit', 'choom' ] );
		$items   = $service->check();

		self::assertSame(
			[ 'proc_open', 'proc_get_status', 'proc_close', 'temporary directory', 'gd', 'sysvsem', 'pdftoppm', 'timeout', 'prlimit', 'choom' ],
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
	): PrerequisitesService
	{
		$finder = $this->createMock( IBinaryFinder::class );
		$finder->method( 'findBinaryPath' )
		       ->willReturnCallback(
			       static fn(
				       string $program,
			       ): string|false => in_array( $program, $found, true ) ? '/usr/bin/' . $program : false,
		       )
		;

		$config = $this->createMock( IConfig::class );
		$config->method( 'getSystemValue' )
		       ->willReturn( [] )
		;

		$tempManager = $this->createMock( ITempManager::class );
		$tempManager->method( 'getTempBaseDir' )
		            ->willReturn( $temp === '' ? sys_get_temp_dir() : $temp )
		;

		return new PrerequisitesService(
			new ConfigurationService( $this->memoryAppConfig(), $this->englishL10n() ),
			new BinaryLocator( $config, $finder, $this->createMock( LoggerInterface::class ) ),
			$runner ?? $this->createMock( CommandRunner::class ),
			$tempManager,
		);
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
