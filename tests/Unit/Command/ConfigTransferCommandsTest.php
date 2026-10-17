<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Unit\Command;

use DateTime;
use DateTimeZone;
use OCA\LargeFilePreviews\Command\ConfigExport;
use OCA\LargeFilePreviews\Command\ConfigImport;
use OCA\LargeFilePreviews\Config\ConfigLexicon;
use OCA\LargeFilePreviews\Service\ConfigTransfer;
use OCA\LargeFilePreviews\Service\PrerequisitesService;
use OCA\LargeFilePreviews\Service\ScratchDirectory;
use OCA\LargeFilePreviews\Tests\Unit\MemoryAppConfig;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class ConfigTransferCommandsTest
    extends
    TestCase
{
	use MemoryAppConfig;


//  private properties

	private string $file = '';


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();
		$this->file = (string) tempnam( sys_get_temp_dir(), 'lfp-config-' );
	}


//  other non-static methods

	protected function tearDown(): void
	{
		@unlink( $this->file );
	}

	public function testExportWritesWhatIsStoredToStandardOutputOrAFile(): void
	{
		$this->appValues = [ ConfigLexicon::TIMEOUT_SECONDS => 45 ];
		$tester          = new CommandTester( new ConfigExport( $this->transfer() ) );

		self::assertSame( 0, $tester->execute( [] ) );
		self::assertSame( [ 'timeout_seconds' => 45 ], json_decode( $tester->getDisplay(), true )['settings'] ?? null );

		self::assertSame( 0, $tester->execute( [ 'file' => $this->file ] ) );
		self::assertSame( 'Written to ' . $this->file . '.', trim( $tester->getDisplay() ) );
		self::assertSame( [ 'timeout_seconds' => 45 ], json_decode( (string) file_get_contents( $this->file ), true )['settings'] ?? null );
	}

	public function testADryRunSaysWhatWouldChangeAndChangesNothing(): void
	{
		$this->writeFile( [ 'settings' => [ 'timeout_seconds' => 60 ] ] );
		$tester = $this->import();

		self::assertSame( 0, $tester->execute( [ 'file' => $this->file, '--dry-run' => true ] ) );
		self::assertStringContainsString( 'written by version 0.0.9 on 2026-01-01', $tester->getDisplay() );
		self::assertMatchesRegularExpression( '/settings\s*\|\s*timeout_seconds\s*\|\s*-\s*\|\s*60/', $tester->getDisplay() );
		self::assertStringContainsString( 'Dry run: 1 change(s), nothing written.', $tester->getDisplay() );
		self::assertSame( [], $this->appValues );
	}

	public function testAnImportWritesAndSaysSo(): void
	{
		$this->writeFile( [ 'settings' => [ 'timeout_seconds' => 60 ] ] );

		self::assertSame( 0, $this->import()->execute( [ 'file' => $this->file ] ) );
		self::assertSame( 60, $this->appValues[ ConfigLexicon::TIMEOUT_SECONDS ] );
	}

	public function testWhatIsLeftOutIsNamedAndTheRestImportedWithStatus3(): void
	{
		$this->writeFile( [ 'settings' => [ 'timeout_seconds' => 60, 'speed' => 11 ] ] );
		$tester = $this->import();

		self::assertSame( ConfigImport::WARNINGS, $tester->execute( [ 'file' => $this->file ] ) );
		self::assertStringContainsString( 'setting speed: not one this app has; left out', $tester->getDisplay() );
		self::assertSame( 60, $this->appValues[ ConfigLexicon::TIMEOUT_SECONDS ] );
	}

	public function testARefusalWritesNothingWithStatus4(): void
	{
		$this->writeFile( [ 'settings' => [ 'timeout_seconds' => 60, 'choom_adjust' => 5000 ] ] );
		$tester = $this->import();

		self::assertSame( ConfigImport::REFUSED, $tester->execute( [ 'file' => $this->file ] ) );
		self::assertStringContainsString( 'Refused: choom_adjust must be between 0 and 1000. Nothing was written.', $tester->getDisplay() );
		self::assertSame( [], $this->appValues );
	}

	public function testAFileThatCannotBeReadIsAWrongCall(): void
	{
		self::assertSame( 2, $this->import()->execute( [ 'file' => '/no/such/file.json' ] ) );
	}

	/**
	 * Without a file, or with -, what is piped in is read; at a terminal,
	 * where nothing is, it is a wrong call.
	 */
	public function testStandardInputIsReadWithoutAFileOrWithDash(): void
	{
		$text = (string) json_encode( [ 'app' => 'large_file_previews', 'format' => 1, 'settings' => [ 'timeout_seconds' => 60 ] ] );

		self::assertSame( 0, $this->piped( $text )->execute( [] ) );
		self::assertSame( 60, $this->appValues[ ConfigLexicon::TIMEOUT_SECONDS ] );

		$this->appValues = [];

		self::assertSame( 0, $this->piped( $text )->execute( [ 'file' => '-' ] ) );
		self::assertSame( 60, $this->appValues[ ConfigLexicon::TIMEOUT_SECONDS ] );

		$terminal = $this->piped( null );

		self::assertSame( 2, $terminal->execute( [] ) );
		self::assertStringContainsString( 'Give a file, or pipe one in.', $terminal->getDisplay() );
	}

	/**
	 * Nextcloud's qualities are not written; their commands, alone on
	 * standard output, are what a shell would run; the rest is on standard
	 * error. The same on a dry run.
	 */
	public function testNextcloudsCommandsAloneOnStandardOutput(): void
	{
		$this->writeFile( [ 'settings' => [ 'timeout_seconds' => 60 ], 'nextcloud' => [ 'appconfig' => [ 'preview' => [ 'jpeg_quality' => 70 ] ] ] ] );

		foreach ( [ [ 'file' => $this->file, '--dry-run' => true ], [ 'file' => $this->file ] ] as $call )
		{
			$tester = $this->import();

			self::assertSame( 0, $tester->execute( $call, [ 'capture_stderr_separately' => true ] ) );
			self::assertSame( "occ config:app:set preview jpeg_quality --value=70 --type=integer\n", $tester->getDisplay() );
			self::assertStringContainsString( 'timeout_seconds', $tester->getErrorOutput() );
			self::assertStringContainsString( 'their commands follow on standard output', $tester->getErrorOutput() );
		}

		self::assertArrayNotHasKey( 'jpeg_quality', $this->appValues );
	}

	/**
	 * An import whose standard input is the text given, or a terminal where
	 * it is null.
	 */
	private function piped( ?string $text ): CommandTester
	{
		return new CommandTester(
			new class (
				$this->transfer(),
				$text,
			)
			    extends
			    ConfigImport
			{

//  constructor

				public function __construct(
					ConfigTransfer           $transfer,
					private readonly ?string $text,
				)
				{
					parent::__construct( $transfer );
				}


//  other non-static methods

				#[\Override]
				protected function standardInput(): ?string
				{
					return $this->text;
				}
			},
		);
	}

	private function import(): CommandTester
	{
		return new CommandTester( new ConfigImport( $this->transfer() ) );
	}

	/**
	 * @param array<string, mixed> $parts
	 */
	private function writeFile( array $parts ): void
	{
		file_put_contents(
			$this->file,
			(string) json_encode( [ 'app' => 'large_file_previews', 'format' => 1, 'exported' => [ 'version' => '0.0.9', 'date' => '2026-01-01' ], ...$parts ] ),
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
			$this->createMock( PrerequisitesService::class ),
		);
	}
}
