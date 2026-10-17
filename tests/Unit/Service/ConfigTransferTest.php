<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Unit\Service;

use DateTime;
use DateTimeZone;
use OCA\LargeFilePreviews\Config\ConfigLexicon;
use OCA\LargeFilePreviews\Config\ConfigurationException;
use OCA\LargeFilePreviews\Config\TransferChange;
use OCA\LargeFilePreviews\Config\TransferWarning;
use OCA\LargeFilePreviews\Service\BinaryLocator;
use OCA\LargeFilePreviews\Service\ConfigTransfer;
use OCA\LargeFilePreviews\Service\ScratchDirectory;
use OCA\LargeFilePreviews\Tests\Unit\MemoryAppConfig;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ITempManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConfigTransferTest
    extends
    TestCase
{
	use MemoryAppConfig;


//  other non-static methods

	public function testExportHoldsWhatIsStoredOnly(): void
	{
		$this->appValues    = [
			ConfigLexicon::TIMEOUT_SECONDS => 45,
			ConfigLexicon::MIMETYPES       => [ 'image/tiff' => [ 'enabled' => true, 'min_size_mb' => 800 ] ],
			ConfigLexicon::EXTENSIONS      => [ 'tif' => [ 'enabled' => false ] ],
		];
		$this->systemValues = [
			BinaryLocator::SYSTEM_KEY                 => [ 'pdftoppm' => '/opt/poppler/bin/pdftoppm' ],
			ConfigLexicon::ALLOW_UNTRUSTED_SYSTEM_KEY => true,
		];

		self::assertSame(
			[
				'app'        => 'large_file_previews',
				'format'     => 1,
				'exported'   => [ 'version' => '0.1.0', 'date' => '2026-10-17T02:18:00+00:00' ],
				'settings'   => [ 'timeout_seconds' => 45 ],
				'mimetypes'  => [ 'image/tiff' => [ 'enabled' => true, 'min_size_mb' => 800 ] ],
				'extensions' => [ 'tif' => [ 'enabled' => false ] ],
				'system'     => [
					'binaries'             => [ 'pdftoppm' => '/opt/poppler/bin/pdftoppm' ],
					'vips_allow_untrusted' => true,
				],
			],
			$this->transfer()->export(),
		);
	}

	/**
	 * Nothing stored: each part an empty object, not a list.
	 */
	public function testAnEmptyConfigurationExportsEmptyObjects(): void
	{
		$json = (string) json_encode( $this->transfer()->export() );

		self::assertStringContainsString( '"settings":{},"mimetypes":{},"extensions":{},"system":{}', $json );
	}

	public function testWhatWasExportedIsRestored(): void
	{
		$this->appValues    = [
			ConfigLexicon::TIMEOUT_SECONDS    => 45,
			ConfigLexicon::MIN_SIZE_ALL_TYPES => true,
			ConfigLexicon::MIMETYPES          => [ 'application/pdf' => [ 'enabled' => true ], 'image/tiff' => [ 'min_size_mb' => 800 ] ],
			ConfigLexicon::EXTENSIONS         => [ 'tif' => [ 'enabled' => false ] ],
		];
		$this->systemValues = [ BinaryLocator::SYSTEM_KEY => [ 'pdftoppm' => '/bin/sh' ] ];
		$stored             = [ $this->appValues, $this->systemValues ];
		$file               = (string) json_encode( $this->transfer()->export() );

		$this->appValues    = [ ConfigLexicon::CHOOM_ADJUST => 10 ];
		$this->systemValues = [];
		$transfer           = $this->transfer();
		$transfer->apply( $transfer->read( $file ), replace: true, system: true );

		self::assertEquals( $stored, [ $this->appValues, $this->systemValues ] );
	}

	public function testAMergeChangesWhatTheFileNamesAndAReplaceTheRestToo(): void
	{
		$this->appValues = [
			ConfigLexicon::TIMEOUT_SECONDS => 45,
			ConfigLexicon::CHOOM_ADJUST    => 10,
			ConfigLexicon::EXTENSIONS      => [ 'tif' => [ 'enabled' => false ] ],
		];
		$file            = $this->file( [ 'settings' => [ 'timeout_seconds' => 60 ] ] );
		$transfer        = $this->transfer();

		self::assertEquals(
			[ new TransferChange( TransferChange::SETTINGS, 'timeout_seconds', 45, 60 ) ],
			$transfer->changes( $transfer->read( $file ), replace: false ),
		);
		self::assertEquals(
			[
				new TransferChange( TransferChange::SETTINGS, 'timeout_seconds', 45, 60 ),
				new TransferChange( TransferChange::SETTINGS, 'choom_adjust', 10, null ),
				new TransferChange( TransferChange::EXTENSIONS, 'tif', [ 'enabled' => false ], null ),
			],
			$transfer->changes( $transfer->read( $file ), replace: true ),
		);

		$transfer->apply( $transfer->read( $file ), replace: false, system: false );

		self::assertSame( 60, $this->appValues[ ConfigLexicon::TIMEOUT_SECONDS ] );
		self::assertSame( 10, $this->appValues[ ConfigLexicon::CHOOM_ADJUST ] );
	}

	/**
	 * A rule in the file is the rule: the fields it does not set are
	 * cleared, not kept from what was stored.
	 */
	public function testARuleIsWhatTheFileSays(): void
	{
		$this->appValues = [ ConfigLexicon::MIMETYPES => [ 'image/tiff' => [ 'enabled' => true, 'min_size_mb' => 800 ] ] ];
		$transfer        = $this->transfer();

		$transfer->apply( $transfer->read( $this->file( [ 'mimetypes' => [ 'image/tiff' => [ 'max_size_mb' => 2000 ] ] ] ) ), replace: false, system: false );

		self::assertSame( [ 'image/tiff' => [ 'max_size_mb' => 2000 ] ], $this->appValues[ ConfigLexicon::MIMETYPES ] );
	}

	public function testWhatTheAppDoesNotKnowIsLeftOutAndNamed(): void
	{
		$transfer = $this->transfer();
		$read     = $transfer->read(
			$this->file(
				[
					'comments'   => 'mine',
					'settings'   => [ 'timeout_seconds' => 60, 'speed' => 11 ],
					'mimetypes'  => [ 'image/tiff' => [ 'enabled' => true, 'colour' => 'red' ], 'text/plain' => [ 'enabled' => true ] ],
					'extensions' => [ 'txt' => [ 'enabled' => true ], 'tif' => [ 'enabled' => false ] ],
					'system'     => [ 'binaries' => [ 'gs' => '/usr/bin/gs' ], 'theme' => 'dark' ],
				],
			),
		);

		self::assertSame(
			[
				'part comments: not one this app reads; left out',
				'setting speed: not one this app has; left out',
				'field image/tiff.colour: not one a rule has; left out',
				'MIME type text/plain: not one this app handles; left out',
				'extension txt: of a type this app does not handle; left out',
				'program gs: not one this app runs; left out',
				'system value theme: not one this app has; left out',
			],
			array_map( static fn( TransferWarning $warning ): string => $warning->text(), $read->warnings ),
		);
		self::assertSame( [ 'timeout_seconds' => 60 ], $read->settings );
		self::assertSame( [ 'image/tiff' ], array_keys( $read->mimetypes ) );
		self::assertSame( [ 'tif' ], array_keys( $read->extensions ) );
		self::assertSame( [], $read->binaries );
	}

	#[DataProvider( 'refusedFiles' )]
	public function testAFileThatCannotBeTakenIsRefused(
		string $file,
		string $message,
	): void
	{
		$this->expectException( ConfigurationException::class );
		$this->expectExceptionMessage( $message );

		$this->transfer()->read( $file );
	}

	/**
	 * Read alone, each value is valid; together with what the type ships
	 * with, one is not, and the setting beside it is not written either.
	 */
	public function testNothingIsWrittenWhereOneChangeIsRefused(): void
	{
		$transfer = $this->transfer();
		$read     = $transfer->read(
			$this->file(
				[
					'settings'  => [ 'timeout_seconds' => 60 ],
					'mimetypes' => [ 'image/tiff' => [ 'max_size_mb' => 10 ] ],
				],
			),
		);

		try
		{
			$transfer->apply( $read, replace: false, system: false );
			self::fail( 'No refusal' );
		}
		catch ( ConfigurationException $refusal )
		{
			self::assertStringContainsString( 'above', $refusal->getMessage() );
		}

		self::assertSame( [], $this->appValues );
	}

	/**
	 * occ sets the system values, after checking each as config:set does,
	 * and before writing anything; the page leaves them alone.
	 */
	public function testSystemValuesAreCheckedFirstAndSetByOccOnly(): void
	{
		$transfer = $this->transfer();
		$wrong    = $transfer->read( $this->file( [ 'settings' => [ 'timeout_seconds' => 60 ], 'system' => [ 'binaries' => [ 'pdftoppm' => '/no/such/pdftoppm' ] ] ] ) );

		try
		{
			$transfer->apply( $wrong, replace: false, system: true );
			self::fail( 'No refusal' );
		}
		catch ( ConfigurationException $refusal )
		{
			self::assertSame( 'binaries.pdftoppm: /no/such/pdftoppm does not exist.', $refusal->getMessage() );
		}

		self::assertSame( [], $this->appValues );

		$transfer->apply( $wrong, replace: false, system: false );

		self::assertSame( 60, $this->appValues[ ConfigLexicon::TIMEOUT_SECONDS ] );
		self::assertSame( [], $this->systemValues );

		$right = $transfer->read( $this->file( [ 'system' => [ 'binaries' => [ 'pdftoppm' => '/bin/sh' ], 'vips_allow_untrusted' => true ] ] ) );
		$transfer->apply( $right, replace: false, system: true );

		self::assertSame(
			[ BinaryLocator::SYSTEM_KEY => [ 'pdftoppm' => '/bin/sh' ], ConfigLexicon::ALLOW_UNTRUSTED_SYSTEM_KEY => true ],
			$this->systemValues,
		);
	}

	private function transfer(): ConfigTransfer
	{
		$apps = $this->createMock( IAppManager::class );
		$apps->method( 'getAppVersion' )->willReturn( '0.1.0' );
		$time = $this->createMock( ITimeFactory::class );
		$time->method( 'getDateTime' )->willReturn( new DateTime( '2026-10-17T02:18:00', new DateTimeZone( 'UTC' ) ) );

		return new ConfigTransfer(
			$this->settings(),
			$this->binaryLocator( [] ),
			new ScratchDirectory( $this->systemConfig(), $this->createMock( ITempManager::class ) ),
			$apps,
			$time,
			$this->englishL10n(),
		);
	}

	/**
	 * @param array<string, mixed> $parts
	 */
	private function file( array $parts ): string
	{
		return (string) json_encode( [ 'app' => 'large_file_previews', 'format' => 1, ...$parts ] );
	}


//  static methods

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function refusedFiles(): iterable
	{
		$file = static fn( array $parts ): string => (string) json_encode( [ 'app' => 'large_file_previews', 'format' => 1, ...$parts ] );

		yield 'not JSON' => [ '{"app":', 'The file is not JSON.' ];
		yield 'a list' => [ '[1, 2]', 'The file is not a configuration of this app.' ];
		yield 'another app\'s' => [ '{"app":"files","format":1}', 'The file is not a configuration of this app.' ];
		yield 'no format' => [ '{"app":"large_file_previews"}', 'The file names no format.' ];
		yield 'a newer format' => [ '{"app":"large_file_previews","format":2}', 'The file is of format 2; this app reads format 1 and older.' ];
		yield 'settings a list' => [ $file( [ 'settings' => [ 1, 2 ] ] ), 'The part settings of the file is not an object.' ];
		yield 'a setting out of range' => [ $file( [ 'settings' => [ 'timeout_seconds' => 9999 ] ] ), 'timeout_seconds must be between 1 and 600.' ];
		yield 'a setting an object' => [ $file( [ 'settings' => [ 'timeout_seconds' => [ 30 ] ] ] ), 'timeout_seconds is not a value a setting can have.' ];
		yield 'a rule not valid' => [ $file( [ 'mimetypes' => [ 'image/tiff' => [ 'min_size_mb' => -5 ] ] ] ), 'The rule of image/tiff is not valid: min_size_mb is below 0.' ];
		yield 'untrusted not a switch' => [ $file( [ 'system' => [ 'vips_allow_untrusted' => 'yes' ] ] ), 'vips_allow_untrusted is not true or false.' ];
		yield 'a path not a path' => [ $file( [ 'system' => [ 'binaries' => [ 'pdftoppm' => 7 ] ] ] ), 'binaries.pdftoppm is not a path.' ];
	}
}
