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
use OCA\LargeFilePreviews\Service\ScratchDirectory;
use OCA\LargeFilePreviews\Service\TypePolicy;
use OCA\LargeFilePreviews\Tests\Unit\EnglishL10n;
use OCA\LargeFilePreviews\Tests\Unit\MemoryAppConfig;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\IStorage;
use OCP\IBinaryFinder;
use OCP\IConfig;
use OCP\ITempManager;
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

	private BinaryLocator $binaries;

	private ScratchDirectory $scratch;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		// System config, as the commands leave it, is $this->systemValues.
		$system = $this->systemConfig();

		$this->config = new ConfigurationService( $this->memoryAppConfig(), $this->englishL10n(), $this->mimeDetector(), $system );

		$this->binaries = new BinaryLocator(
			$system,
			$this->createMock( IBinaryFinder::class ),
			$this->detectionCache(),
			$this->createMock( LoggerInterface::class ),
		);

		$tempManager = $this->createMock( ITempManager::class );
		$tempManager->method( 'getTempBaseDir' )
		            ->willReturn( sys_get_temp_dir() )
		;
		$this->scratch = new ScratchDirectory( $system, $tempManager );
	}


//  other non-static methods

	/**
	 * The scratch directory goes to system config, after being checked;
	 * unset, it is Nextcloud's temporary directory again.
	 */
	public function testTheScratchDirectoryIsSetInSystemConfigAndUnset(): void
	{
		$set = new CommandTester( new ConfigSet( $this->config, $this->binaries, $this->scratch ) );

		self::assertSame( Command::SUCCESS, $set->execute( [ 'key' => 'scratch_directory', 'value' => __DIR__ . '/' ] ) );
		self::assertSame( [ 'large_file_previews.scratch_directory' => __DIR__ ], $this->systemValues );
		self::assertSame( [], $this->appValues );
		self::assertSame( Command::FAILURE, $set->execute( [ 'key' => 'scratch_directory', 'value' => __FILE__ ] ) );
		self::assertStringContainsString( 'is not a directory', $set->getDisplay() );

		$unset = new CommandTester( new ConfigUnset( $this->config, $this->binaries, $this->scratch ) );

		self::assertSame( Command::SUCCESS, $unset->execute( [ 'key' => 'scratch_directory' ] ) );
		self::assertSame( [], $this->systemValues );
		self::assertSame( rtrim( sys_get_temp_dir(), '/' ), $this->scratch->path() );
	}

	/**
	 * A path goes to system config, after being checked; never to app
	 * config.
	 */
	public function testAProgramsPathIsSetInSystemConfigAndUnset(): void
	{
		$set = new CommandTester( new ConfigSet( $this->config, $this->binaries, $this->scratch ) );

		self::assertSame( Command::SUCCESS, $set->execute( [ 'key' => 'binaries.pdftoppm', 'value' => PHP_BINARY ] ) );
		self::assertSame( [ 'large_file_previews.binaries' => [ 'pdftoppm' => PHP_BINARY ] ], $this->systemValues );
		self::assertSame( [], $this->appValues );

		self::assertSame( Command::FAILURE, $set->execute( [ 'key' => 'binaries.pdftoppm', 'value' => 'bin/pdftoppm' ] ) );
		self::assertStringContainsString( 'is not an absolute path', $set->getDisplay() );
		self::assertSame( Command::FAILURE, $set->execute( [ 'key' => 'binaries.bash', 'value' => '/bin/bash' ] ) );
		self::assertStringContainsString( 'bash is not a program this app runs', $set->getDisplay() );

		$unset = new CommandTester( new ConfigUnset( $this->config, $this->binaries, $this->scratch ) );

		self::assertSame( Command::SUCCESS, $unset->execute( [ 'key' => 'binaries.pdftoppm' ] ) );
		self::assertSame( [], $this->systemValues );
	}

	/**
	 * Untrusted decoders are allowed in system config only, and blocked
	 * again when unset; no setting in app config takes the name.
	 */
	public function testUntrustedDecodersAreAllowedInSystemConfigAndUnset(): void
	{
		$set = new CommandTester( new ConfigSet( $this->config, $this->binaries, $this->scratch ) );

		self::assertSame( Command::SUCCESS, $set->execute( [ 'key' => 'vips_allow_untrusted', 'value' => 'true' ] ) );
		self::assertSame( [ ConfigLexicon::ALLOW_UNTRUSTED_SYSTEM_KEY => true ], $this->systemValues );
		self::assertSame( [], $this->appValues );
		self::assertTrue( $this->config->untrustedAllowed() );

		self::assertSame( Command::INVALID, $set->execute( [ 'key' => 'vips_allow_untrusted', 'value' => 'perhaps' ] ) );
		self::assertStringContainsString( 'vips_allow_untrusted takes true or false.', $set->getDisplay() );
		self::assertSame( Command::FAILURE, $set->execute( [ 'key' => 'vips_block_untrusted', 'value' => 'false' ] ) );

		$unset = new CommandTester( new ConfigUnset( $this->config, $this->binaries, $this->scratch ) );

		self::assertSame( Command::SUCCESS, $unset->execute( [ 'key' => 'vips_allow_untrusted' ] ) );
		self::assertSame( [], $this->systemValues );
		self::assertFalse( $this->config->untrustedAllowed() );
	}

	public function testNamesAndAliases(): void
	{
		$set = new ConfigSet( $this->config, $this->binaries, $this->scratch );

		self::assertSame( 'large-file-previews:config:set', $set->getName() );
		self::assertSame( [ 'lfp:config:set' ], $set->getAliases() );
	}

	public function testSetAScalar(): void
	{
		$tester = new CommandTester( new ConfigSet( $this->config, $this->binaries, $this->scratch ) );

		self::assertSame( Command::SUCCESS, $tester->execute( [ 'key' => 'timeout_seconds', 'value' => '45' ] ) );
		self::assertSame( 45, $this->appValues[ ConfigLexicon::TIMEOUT_SECONDS ] );
		self::assertStringContainsString( 'timeout_seconds: 45', $tester->getDisplay() );
	}

	public function testSetARuleAndARefusal(): void
	{
		$tester = new CommandTester( new ConfigSet( $this->config, $this->binaries, $this->scratch ) );

		self::assertSame( Command::SUCCESS, $tester->execute( [ '--ext' => 'tif', '--enabled' => 'yes', '--min' => '200' ] ) );
		self::assertSame( [ 'tif' => [ 'enabled' => true, 'min_size_mb' => 200 ] ], $this->appValues[ ConfigLexicon::EXTENSIONS ] );

		self::assertSame( Command::FAILURE, $tester->execute( [ '--ext' => 'tif', '--max' => '100' ] ) );
		self::assertStringContainsString( 'min_size_mb is above max_size_mb.', $tester->getDisplay() );

		self::assertSame( Command::FAILURE, $tester->execute( [ '--mime' => 'image/png', '--enabled' => 'perhaps' ] ) );
		self::assertStringContainsString( '--enabled takes yes or no.', $tester->getDisplay() );
	}

	public function testSetNeedsSomethingToSet(): void
	{
		$tester = new CommandTester( new ConfigSet( $this->config, $this->binaries, $this->scratch ) );

		self::assertSame( Command::INVALID, $tester->execute( [ '--mime' => 'image/png' ] ) );
		self::assertSame( Command::INVALID, $tester->execute( [ '--mime' => 'image/png', '--ext' => 'png', '--min' => '1' ] ) );
		self::assertSame( Command::INVALID, $tester->execute( [ 'key' => 'timeout_seconds' ] ) );
		self::assertSame( [], $this->appValues );
	}

	public function testUnset(): void
	{
		$this->config->setScalar( ConfigLexicon::CHOOM_ADJUST, 500 );
		$this->config->setExtensionRule( 'tif', new TypeRule( true ) );
		$tester = new CommandTester( new ConfigUnset( $this->config, $this->binaries, $this->scratch ) );

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

		// As the page says it: an extension of no known type has none.
		$tester->execute( [ '--ext' => 'xyz', '--output' => 'json' ] );

		self::assertNull( json_decode( $tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR )['mime'] );
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
				new BinaryLocator( $config, $finder, $this->detectionCache(), $this->createMock( LoggerInterface::class ) ),
				$this->scratch,
			),
		);

		self::assertSame( Command::SUCCESS, $tester->execute( [ '--output' => 'json' ] ) );
		$result = json_decode( $tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR );

		self::assertSame( [ 'stored', 'programs', 'scratch', 'untrustedAllowed', 'resolved', 'problems' ], array_keys( $result ) );
		self::assertSame( [ 'path' => rtrim( sys_get_temp_dir(), '/' ), 'source' => 'temporary directory' ], $result['scratch'] );
		self::assertFalse( $result['untrustedAllowed'] );
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

		return new ConfigGet( new TypePolicy( $this->config ), $this->config, $root );
	}
}
