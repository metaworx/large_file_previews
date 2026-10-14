<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Unit\Command;

use OCA\LargeFilePreviews\Command\ConfigGet;
use OCA\LargeFilePreviews\Command\ConfigSet;
use OCA\LargeFilePreviews\Command\ConfigShow;
use OCA\LargeFilePreviews\Command\ConfigUnset;
use OCA\LargeFilePreviews\Config\ConfigLexicon;
use OCA\LargeFilePreviews\Config\TypeRule;
use OCA\LargeFilePreviews\Service\BinaryLocator;
use OCA\LargeFilePreviews\Service\ConfigurationService;
use OCA\LargeFilePreviews\Service\TypePolicy;
use OCA\LargeFilePreviews\Tests\Unit\EnglishL10n;
use OCA\LargeFilePreviews\Tests\Unit\MemoryAppConfig;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\IStorage;
use OCP\IBinaryFinder;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The four config commands against an in-memory app config: what each
 * writes, refuses and prints.
 */
class ConfigCommandsTest
    extends
    TestCase
{
	use EnglishL10n;
	use MemoryAppConfig;


//  private properties

	private ConfigurationService $config;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->config = new ConfigurationService( $this->memoryAppConfig(), $this->englishL10n(), $this->mimeDetector() );
	}


//  other non-static methods

	public function testNamesAndAliases(): void
	{
		$set = new ConfigSet( $this->config );

		self::assertSame( 'large-file-previews:config:set', $set->getName() );
		self::assertSame( [ 'lfp:config:set' ], $set->getAliases() );
	}

	public function testSetAScalar(): void
	{
		$tester = new CommandTester( new ConfigSet( $this->config ) );

		self::assertSame( Command::SUCCESS, $tester->execute( [ 'key' => 'timeout_seconds', 'value' => '45' ] ) );
		self::assertSame( 45, $this->appValues[ ConfigLexicon::TIMEOUT_SECONDS ] );
		self::assertStringContainsString( 'timeout_seconds: 45', $tester->getDisplay() );
	}

	public function testSetARuleAndARefusal(): void
	{
		$tester = new CommandTester( new ConfigSet( $this->config ) );

		self::assertSame( Command::SUCCESS, $tester->execute( [ '--ext' => 'tif', '--enabled' => 'yes', '--min' => '200' ] ) );
		self::assertSame( [ 'tif' => [ 'enabled' => true, 'min_size_mb' => 200 ] ], $this->appValues[ ConfigLexicon::EXTENSIONS ] );

		self::assertSame( Command::FAILURE, $tester->execute( [ '--ext' => 'tif', '--max' => '100' ] ) );
		self::assertStringContainsString( 'min_size_mb is above max_size_mb.', $tester->getDisplay() );

		self::assertSame( Command::FAILURE, $tester->execute( [ '--mime' => 'image/png', '--enabled' => 'perhaps' ] ) );
		self::assertStringContainsString( '--enabled takes yes or no.', $tester->getDisplay() );
	}

	public function testSetNeedsSomethingToSet(): void
	{
		$tester = new CommandTester( new ConfigSet( $this->config ) );

		self::assertSame( Command::INVALID, $tester->execute( [ '--mime' => 'image/png' ] ) );
		self::assertSame( Command::INVALID, $tester->execute( [ '--mime' => 'image/png', '--ext' => 'png', '--min' => '1' ] ) );
		self::assertSame( Command::INVALID, $tester->execute( [ 'key' => 'timeout_seconds' ] ) );
		self::assertSame( [], $this->appValues );
	}

	public function testUnset(): void
	{
		$this->config->setScalar( ConfigLexicon::CHOOM_ADJUST, 500 );
		$this->config->setExtensionRule( 'tif', new TypeRule( true ) );
		$tester = new CommandTester( new ConfigUnset( $this->config ) );

		self::assertSame( Command::SUCCESS, $tester->execute( [ 'key' => 'choom_adjust' ] ) );
		self::assertSame( Command::SUCCESS, $tester->execute( [ '--ext' => 'tif' ] ) );
		self::assertSame( [], $this->appValues );

		self::assertSame( Command::FAILURE, $tester->execute( [ '--mime' => 'image/tiff', '--field' => [ 'colour' ] ] ) );
	}

	public function testGetDerivesTheMimeTypeFromTheExtension(): void
	{
		$tester = new CommandTester( $this->get() );

		$tester->execute( [ '--ext' => 'tif', '--size' => '60mb', '--output' => 'json' ] );
		$result = json_decode( $tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR );

		self::assertSame( 'image/tiff', $result['mime'] );
		self::assertTrue( $result['mime_derived'] );
		self::assertSame( 62_914_560, $result['size'] );
		self::assertSame( 'disabled', $result['verdict'] );
	}

	public function testGetWithoutASizeGivesNoVerdictForAnEnabledType(): void
	{
		$tester = new CommandTester( $this->get() );

		$tester->execute( [ '--mime' => 'application/pdf' ] );

		self::assertStringContainsString( 'enabled      yes (mime)', $tester->getDisplay() );
		self::assertStringNotContainsString( 'Verdict', $tester->getDisplay() );
	}

	public function testGetRefusesWhatIsNotASizeOrNothingToResolve(): void
	{
		$tester = new CommandTester( $this->get() );

		self::assertSame( Command::INVALID, $tester->execute( [] ) );
		self::assertSame( Command::INVALID, $tester->execute( [ '--mime' => 'application/pdf', '--size' => 'big' ] ) );
		self::assertSame( Command::INVALID, $tester->execute( [ '--mime' => 'application/pdf', '--output' => 'xml' ] ) );
	}

	public function testGetAFileSaysWhetherItsStorageIsRendered(): void
	{
		$storage = $this->createMock( IStorage::class );
		$storage->method( 'isLocal' )
		        ->willReturn( false )
		;

		$file = $this->createMock( File::class );
		$file->method( 'getMimetype' )
		     ->willReturn( 'application/pdf' )
		;
		$file->method( 'getName' )
		     ->willReturn( 'scan.pdf' )
		;
		$file->method( 'getSize' )
		     ->willReturn( 1000 )
		;
		$file->method( 'getStorage' )
		     ->willReturn( $storage )
		;
		$file->method( 'isEncrypted' )
		     ->willReturn( false )
		;

		$tester = new CommandTester( $this->get( [ '/alice/files/scan.pdf' => $file ] ) );

		self::assertSame( Command::SUCCESS, $tester->execute( [ '--file' => '/alice/files/scan.pdf' ] ) );
		self::assertStringContainsString( 'Verdict      taken', $tester->getDisplay() );
		self::assertStringContainsString( 'not local, not encrypted - the app renders only local, unencrypted files', $tester->getDisplay() );

		self::assertSame( Command::FAILURE, $tester->execute( [ '--file' => '/alice/files/gone.pdf' ] ) );
		self::assertSame( Command::FAILURE, $tester->execute( [ '--file' => '/alice/files' ] ) );
	}

	public function testShowListsEverySectionAsJson(): void
	{
		$this->appValues[ ConfigLexicon::EXTENSIONS ] = [ 'tif' => [ 'enabled' => true ], 'BAD' => [] ];

		$detector = $this->createMock( IMimeTypeDetector::class );
		$detector->method( 'detectPath' )
		         ->willReturn( 'image/tiff' )
		;

		$config = $this->createMock( IConfig::class );
		$config->method( 'getSystemValue' )
		       ->willReturn( [] )
		;

		$finder = $this->createMock( IBinaryFinder::class );
		$finder->method( 'findBinaryPath' )
		       ->willReturn( false )
		;

		$tester = new CommandTester(
			new ConfigShow(
				$this->config,
				new TypePolicy( $this->config ),
				new BinaryLocator( $config, $finder, $this->createMock( LoggerInterface::class ) ),
				$detector,
			),
		);

		self::assertSame( Command::SUCCESS, $tester->execute( [ '--output' => 'json' ] ) );
		$result = json_decode( $tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR );

		self::assertSame( [ 'stored', 'programs', 'resolved', 'problems' ], array_keys( $result ) );
		self::assertTrue( $result['stored']['extensions']['stored'] );
		self::assertFalse( $result['stored']['timeout_seconds']['stored'] );
		self::assertNull( $result['programs']['pdftoppm']['path'] );
		// Sixteen MIME types, then the one valid extension.
		self::assertCount( 17, $result['resolved'] );
		self::assertSame( 'ext', $result['resolved'][16]['enabled']['source'] );
		self::assertSame( [ 'extensions.BAD: not a lower-case extension; ignored' ], $result['problems'] );

		self::assertSame( Command::SUCCESS, $tester->execute( [] ) );
		self::assertStringContainsString( 'Set but not used', $tester->getDisplay() );
	}

	/**
	 * @param array<string, File> $files
	 */
	private function get( array $files = [] ): ConfigGet
	{
		$detector = $this->createMock( IMimeTypeDetector::class );
		$detector->method( 'detectPath' )
		         ->willReturnCallback(
			         static fn(
				         string $path,
			         ): string => str_ends_with( $path, '.tif' ) ? 'image/tiff' : 'application/octet-stream',
		         )
		;

		$root = $this->createMock( IRootFolder::class );
		$root->method( 'get' )
		     ->willReturnCallback(
			     function(
				     string $path,
			     ) use (
				     $files,
			     ): File|Folder
			     {
				     if ( isset( $files[ $path ] ) )
				     {
					     return $files[ $path ];
				     }

				     if ( $path === '/alice/files' )
				     {
					     return $this->createMock( Folder::class );
				     }

				     throw new NotFoundException();
			     },
		     )
		;

		return new ConfigGet( new TypePolicy( $this->config ), $detector, $root );
	}
}
