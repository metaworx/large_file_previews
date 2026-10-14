<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Unit\Preview;

use OCA\LargeFilePreviews\Config\ConfigLexicon;
use OCA\LargeFilePreviews\Config\SupportedTypes;
use OCA\LargeFilePreviews\Preview\ImageLoader;
use OCA\LargeFilePreviews\Preview\LargeFileProvider;
use OCA\LargeFilePreviews\Renderer\PageProbe;
use OCA\LargeFilePreviews\Renderer\PopplerRenderer;
use OCA\LargeFilePreviews\Renderer\Renderers;
use OCA\LargeFilePreviews\Renderer\VipsLoaders;
use OCA\LargeFilePreviews\Renderer\VipsRenderer;
use OCA\LargeFilePreviews\Service\BinaryLocation;
use OCA\LargeFilePreviews\Service\BinaryLocator;
use OCA\LargeFilePreviews\Service\ScratchDirectory;
use OCA\LargeFilePreviews\Service\CommandRunner;
use OCA\LargeFilePreviews\Service\ConfigurationService;
use OCA\LargeFilePreviews\Service\Outcome;
use OCA\LargeFilePreviews\Service\RenderOutcome;
use OCA\LargeFilePreviews\Service\TypePolicy;
use OCA\LargeFilePreviews\Tests\Unit\EnglishL10n;
use OCA\LargeFilePreviews\Tests\Unit\MemoryAppConfig;
use OCP\Files\File;
use OCP\Files\Storage\IStorage;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IImage;
use OCP\ITempManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class LargeFileProviderTest
    extends
    TestCase
{
	use EnglishL10n;
	use MemoryAppConfig;


//  private properties

	private CommandRunner&MockObject $runner;

	private ImageLoader&MockObject $images;

	private LoggerInterface&MockObject $logger;

	private string $folder;

	/** @var list<string> the format of each page pdftoppm wrote */
	private array $formats = [];


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->runner = $this->createMock( CommandRunner::class );
		$this->images = $this->createMock( ImageLoader::class );
		$this->logger = $this->createMock( LoggerInterface::class );
		$this->folder = sys_get_temp_dir() . '/lfp-provider-' . bin2hex( random_bytes( 4 ) );
	}


//  other non-static methods

	protected function tearDown(): void
	{
		array_map( unlink( ... ), glob( $this->folder . '/*' ) ?: [] );
		@rmdir( $this->folder );

		parent::tearDown();
	}

	public function testTheRegexIsTheSupportedTypes(): void
	{
		self::assertSame( SupportedTypes::MIME_REGEX, $this->provider()->getMimeType() );
	}

	public function testALocalPdfIsTaken(): void
	{
		self::assertTrue( $this->provider()->isAvailable( $this->file( 'application/pdf', 'a.pdf' ) ) );
	}

	/**
	 * Every image on the instance comes here; off says nothing in the log.
	 */
	public function testADisabledTypeIsDeclinedQuietly(): void
	{
		$this->logger->expects( self::never() )
		             ->method( self::anything() )
		;

		self::assertFalse( $this->provider()->isAvailable( $this->file( 'image/png', 'a.png' ) ) );
	}

	/**
	 * An image switched on goes to libvips, from its minimum up.
	 */
	public function testAnEnabledImageTypeIsTaken(): void
	{
		$this->appValues[ ConfigLexicon::MIMETYPES ] = [ 'image/tiff' => [ 'enabled' => true, 'min_size_mb' => 50 ] ];

		self::assertFalse( $this->provider()->isAvailable( $this->file( 'image/tiff', 'a.tif' ) ) );
		self::assertTrue( $this->provider()->isAvailable( $this->file( 'image/tiff', 'a.tif', 60 * 1_048_576 ) ) );
	}

	/**
	 * A type libvips cannot decode here goes to the next provider, which
	 * may well read it.
	 */
	public function testATypeWithoutItsDecoderIsDeclined(): void
	{
		$this->appValues[ ConfigLexicon::MIMETYPES ] = [
			'image/jp2'  => [ 'enabled' => true ],
			'image/heic' => [ 'enabled' => true ],
		];

		$this->logger->expects( self::exactly( 2 ) )
		             ->method( 'debug' )
		;

		self::assertFalse( $this->provider()->isAvailable( $this->file( 'image/jp2', 'a.jp2' ) ) );
		self::assertFalse( $this->provider()->isAvailable( $this->file( 'image/heic', 'a.heic' ) ) );
	}

	public function testOutsideTheSizeWindowIsDeclined(): void
	{
		$this->appValues[ ConfigLexicon::EXTENSIONS ] = [ 'pdf' => [ 'max_size_mb' => 1 ] ];

		self::assertFalse( $this->provider()->isAvailable( $this->file( 'application/pdf', 'a.pdf', 2 * 1_048_576 ) ) );
	}

	public function testEncryptedOrRemoteIsDeclined(): void
	{
		self::assertFalse( $this->provider()->isAvailable( $this->file( 'application/pdf', 'a.pdf', encrypted: true ) ) );
		self::assertFalse( $this->provider()->isAvailable( $this->file( 'application/pdf', 'a.pdf', local: false ) ) );
	}

	public function testAnExceptionMeansNo(): void
	{
		$file = $this->createMock( File::class );
		$file->method( 'getMimetype' )
		     ->willThrowException( new RuntimeException( 'cache gone' ) )
		;

		$this->logger->expects( self::once() )
		             ->method( 'warning' )
		;

		self::assertFalse( $this->provider()->isAvailable( $file ) );
	}

	public function testARenderIsLoadedAndFittedIntoTheBox(): void
	{
		$this->renders( new RenderOutcome( Outcome::SUCCEEDED, 'exit status 0', 0 ), 4096 );

		$image = $this->createMock( IImage::class );
		$image->expects( self::once() )
		      ->method( 'scaleDownToFit' )
		      ->with( 4096, 2048 )
		;
		$this->images->method( 'load' )
		             ->willReturn( $image )
		;

		self::assertSame( $image, $this->provider()->getThumbnail( $this->file( 'application/pdf', 'a.pdf' ), 4096, 2048 ) );
		self::assertDirectoryDoesNotExist( $this->folder );
	}

	/**
	 * As Nextcloud's preview_format, JPEG unless it says webp; a WebP of a
	 * PDF without libvips is a JPEG; PNG where the app is told so.
	 */
	public function testThePreviewFormat(): void
	{
		$this->renders( new RenderOutcome( Outcome::SUCCEEDED, 'exit status 0', 0 ) );
		$this->images->method( 'load' )
		             ->willReturn( $this->createMock( IImage::class ) )
		;

		$this->provider()->getThumbnail( $this->file( 'application/pdf', 'a.pdf' ), 100, 100 );

		$this->systemValues = [ 'preview_format' => 'webp' ];
		$this->provider()->getThumbnail( $this->file( 'application/pdf', 'a.pdf' ), 100, 100 );

		$this->appValues = [ ConfigLexicon::PREVIEW_FORMAT => 'png' ];
		$this->provider()->getThumbnail( $this->file( 'application/pdf', 'a.pdf' ), 100, 100 );

		self::assertSame( [ 'jpeg', 'jpeg', 'png' ], $this->formats );
	}

	public function testAFailedRenderIsNullAndAWarning(): void
	{
		$this->renders( new RenderOutcome( Outcome::FAILED, 'exit status 1', 1, null, "Error: Incorrect password\nmore" ) );

		$this->logger->expects( self::once() )
		             ->method( 'warning' )
		             ->with(
			             self::anything(),
			             self::callback(
				             static fn(
					             array $context,
				             ): bool => $context['reason'] === 'exit status 1' && $context['stderr'] === '; Error: Incorrect password',
			             ),
		             )
		;

		self::assertNull( $this->provider()->getThumbnail( $this->file( 'application/pdf', 'a.pdf' ), 100, 100 ) );
		self::assertDirectoryDoesNotExist( $this->folder );
		self::assertSame(
			[
				'time'        => 1_760_000_000,
				'mime'        => 'application/pdf',
				'outcome'     => 'failed',
				'reason'      => 'exit status 1',
				'exit_status' => 1,
			],
			$this->appValues[ ConfigLexicon::LAST_FAILURE ],
		);
	}

	public function testASuccessWritesNoRecord(): void
	{
		$this->renders( new RenderOutcome( Outcome::SUCCEEDED, 'exit status 0', 0 ) );
		$this->images->method( 'load' )
		             ->willReturn( $this->createMock( IImage::class ) )
		;

		$this->provider()->getThumbnail( $this->file( 'application/pdf', 'a.pdf' ), 100, 100 );

		self::assertArrayNotHasKey( ConfigLexicon::LAST_FAILURE, $this->appValues );
	}

	public function testOutputThatIsNoImageIsNull(): void
	{
		$this->renders( new RenderOutcome( Outcome::SUCCEEDED, 'exit status 0', 0 ) );
		$this->images->method( 'load' )
		             ->willReturn( null )
		;

		$this->logger->expects( self::once() )
		             ->method( 'warning' )
		             ->with( self::anything(), self::callback( static fn( array $context ): bool => $context['outcome'] === 'invalid_output' ) )
		;

		self::assertNull( $this->provider()->getThumbnail( $this->file( 'application/pdf', 'a.pdf' ), 100, 100 ) );
	}

	public function testNoLocalPathIsNull(): void
	{
		$this->runner->expects( self::never() )
		             ->method( 'run' )
		;

		self::assertNull( $this->provider()->getThumbnail( $this->file( 'application/pdf', 'a.pdf', localPath: false ), 100, 100 ) );
	}

	public function testAThrowingRendererIsNullNotAnException(): void
	{
		$this->runner->method( 'run' )
		             ->willThrowException( new RuntimeException( 'boom' ) )
		;

		self::assertNull( $this->provider()->getThumbnail( $this->file( 'application/pdf', 'a.pdf' ), 100, 100 ) );
		self::assertDirectoryDoesNotExist( $this->folder );
	}

	/**
	 * The runner answers with the outcome, having written the PNG where it
	 * succeeds.
	 */
	private function renders(
		RenderOutcome $outcome,
		?int          $box = null,
	): void
	{
		$this->runner->method( 'run' )
		             ->willReturnCallback(
			             function(
				             string $program,
				             array  $arguments,
			             ) use (
				             $outcome,
				             $box,
			             ): RenderOutcome
			             {
				             if ( $box !== null )
				             {
					             self::assertSame( (string) $box, $arguments[5] );
				             }

				             // pdftoppm writes <stem>.jpg or <stem>.png.
				             if ( $outcome->isSuccess() )
				             {
					             $this->formats[] = in_array( '-jpeg', $arguments, true ) ? 'jpeg' : 'png';
					             file_put_contents( end( $arguments ) . ( in_array( '-jpeg', $arguments, true ) ? '.jpg' : '.png' ), 'image' );
				             }

				             return $outcome;
			             },
		             )
		;
	}

	private function provider(): LargeFileProvider
	{
		$tempManager = $this->createMock( ITempManager::class );
		$tempManager->method( 'getTemporaryFolder' )
		            ->willReturnCallback(
			            function(): string
			            {
				            mkdir( $this->folder );

				            return $this->folder . '/';
			            },
		            )
		;

		$config = new ConfigurationService( $this->memoryAppConfig(), $this->englishL10n(), $this->mimeDetector(), $this->systemConfig() );

		$time = $this->createMock( ITimeFactory::class );
		$time->method( 'getTime' )
		     ->willReturn( 1_760_000_000 )
		;

		// libvips with TIFF, and JPEG 2000 untrusted.
		$loaders = $this->createMock( VipsLoaders::class );
		$loaders->method( 'loaders' )
		        ->willReturn( [ 'tiffload' => false, 'jp2kload' => true ] )
		;

		// No program is found: whether `vips` is there is all that is asked.
		$binaries = $this->createMock( BinaryLocator::class );
		$binaries->method( 'locate' )
		         ->willReturnCallback( static fn( string $program ): BinaryLocation => new BinaryLocation( $program, null, BinaryLocation::SOURCE_FINDER ) )
		;

		$vips = new VipsRenderer( $this->runner, $config, $loaders, $binaries, $this->createMock( ScratchDirectory::class ) );

		return new LargeFileProvider(
			new TypePolicy( $config ),
			// A probe that finds no scan: the box is what was asked for.
			new Renderers(
				new PopplerRenderer( $this->runner, $this->createMock( PageProbe::class ), $vips ),
				$vips,
			),
			$this->images,
			$tempManager,
			$config,
			$time,
			$this->logger,
		);
	}

	private function file(
		string       $mime,
		string       $name,
		int          $size = 1000,
		bool         $local = true,
		bool         $encrypted = false,
		// A file that is there: the renderer opens it; the runner is a mock.
		string|false $localPath = __FILE__,
	): File&MockObject
	{
		$storage = $this->createMock( IStorage::class );
		$storage->method( 'isLocal' )
		        ->willReturn( $local )
		;
		$storage->method( 'getLocalFile' )
		        ->willReturn( $localPath )
		;

		$file = $this->createMock( File::class );
		$file->method( 'getMimetype' )
		     ->willReturn( $mime )
		;
		$file->method( 'getName' )
		     ->willReturn( $name )
		;
		$file->method( 'getSize' )
		     ->willReturn( $size )
		;
		$file->method( 'isEncrypted' )
		     ->willReturn( $encrypted )
		;
		$file->method( 'getStorage' )
		     ->willReturn( $storage )
		;
		$file->method( 'getInternalPath' )
		     ->willReturn( 'files/doc.pdf' )
		;

		return $file;
	}
}
