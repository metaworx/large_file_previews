<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Unit\Renderer;

use OCA\LargeFilePreviews\Config\ConfigLexicon;
use OCA\LargeFilePreviews\Renderer\PreviewFormat;
use OCA\LargeFilePreviews\Renderer\Unsupported;
use OCA\LargeFilePreviews\Renderer\VipsLoaders;
use OCA\LargeFilePreviews\Renderer\VipsRenderer;
use OCA\LargeFilePreviews\Service\BinaryLocation;
use OCA\LargeFilePreviews\Service\BinaryLocator;
use OCA\LargeFilePreviews\Service\CommandRunner;
use OCA\LargeFilePreviews\Service\Deadline;
use OCA\LargeFilePreviews\Service\Outcome;
use OCA\LargeFilePreviews\Service\RenderOutcome;
use OCA\LargeFilePreviews\Service\RunOptions;
use OCA\LargeFilePreviews\Service\ScratchDirectory;
use OCA\LargeFilePreviews\Tests\Unit\MemoryAppConfig;
use PHPUnit\Framework\TestCase;

class VipsRendererTest
    extends
    TestCase
{
	use MemoryAppConfig;


//  private properties

	private string $output;

	/** The image the renderer opens: the runner is a mock, so it holds anything. */
	private string $input;

	/** @var list<array{program: string, arguments: list<string>, keepStdout: bool, environment: array<string, string>, cap: ?int, descriptors: list<int>, fsize: ?int}> */
	private array $runs = [];

	/** @var list<Deadline|null> each run's, in order */
	private array $deadlines = [];


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$folder       = sys_get_temp_dir() . '/lfp-vips-' . bin2hex( random_bytes( 4 ) );
		$this->output = $folder . '/preview.png';
		// A name libvips would have misread, were it given the name.
		$this->input = $folder . '-in/a, out of memory [1]';
		mkdir( $folder );
		mkdir( dirname( $this->input ) );
		file_put_contents( $this->input, 'II*' );
	}


//  other non-static methods

	protected function tearDown(): void
	{
		foreach ( [ dirname( $this->output ), dirname( $this->input ) ] as $folder )
		{
			array_map( unlink( ... ), glob( $folder . '/*' ) ?: [] );
			@rmdir( $folder );
		}

		parent::tearDown();
	}

	public function testItHandlesTheImageTypes(): void
	{
		$renderer = $this->renderer( $this->createMock( CommandRunner::class ) );

		self::assertSame( 'vipsthumbnail', $renderer->program() );
		self::assertTrue( $renderer->supports( 'image/tiff' ) );
		self::assertTrue( $renderer->supports( 'image/heic' ) );
		self::assertFalse( $renderer->supports( 'application/pdf' ) );
		self::assertFalse( $renderer->supports( 'image/svg+xml' ) );
	}

	/**
	 * The header, then the thumbnail: each reading the file as descriptor 4,
	 * with libvips' cap, two arenas, untrusted decoders blocked and the
	 * spill in the render folder.
	 */
	public function testTheCommands(): void
	{
		$this->appValues = [ ConfigLexicon::VIPS_PRLIMIT_AS_MB => 2048 ];

		$outcome = $this->renderer( $this->runner( '/dev/fd/4: 22040x12059 uchar, 3 bands, srgb, tiffload' ) )
		                ->render( $this->input, 4096, $this->output )
		;

		self::assertTrue( $outcome->isSuccess(), $outcome->reason );

		$environment = [
			'MALLOC_ARENA_MAX'     => '2',
			'TMPDIR'               => dirname( $this->output ),
			'VIPS_BLOCK_UNTRUSTED' => '1',
		];

		self::assertSame(
			[
				[
					'program'     => 'vipsheader',
					'arguments'   => [ '/dev/fd/4' ],
					'keepStdout'  => true,
					'environment' => $environment,
					'cap'         => 2048,
					'descriptors' => [ RunOptions::INPUT ],
					'fsize'       => null,
				],
				[
					'program'     => 'vipsthumbnail',
					'arguments'   => [ '/dev/fd/4', '--size', '4096', '--vips-concurrency=1', '-o', $this->output ],
					'keepStdout'  => false,
					'environment' => $environment,
					'cap'         => 2048,
					'descriptors' => [ RunOptions::INPUT ],
					'fsize'       => null,
				],
			],
			$this->runs,
		);
	}

	/**
	 * A JPEG at 95 on the background, white unless told black; a WebP at
	 * 95; a PNG as it is. libvips writes each.
	 */
	public function testTheSaveOptions(): void
	{
		$stem = substr( $this->output, 0, -4 );

		$this->renderer( $this->runner( '' ) )->render( $this->input, 1024, $stem . '.jpg' );
		$this->renderer( $this->runner( '' ) )->render( $this->input, 1024, $stem . '.webp' );

		$this->appValues = [ ConfigLexicon::PREVIEW_BACKGROUND => 'black' ];

		$this->renderer( $this->runner( '' ) )->render( $this->input, 1024, $stem . '.jpg' );

		self::assertSame( $stem . '.jpg[Q=95,background=255]', $this->runs[1]['arguments'][5] );
		self::assertSame( $stem . '.webp[Q=95]', $this->runs[3]['arguments'][5] );
		self::assertSame( $stem . '.jpg[Q=95,background=0]', $this->runs[5]['arguments'][5] );
		self::assertSame( PreviewFormat::WEBP, $this->renderer( $this->runner( '' ) )->format( PreviewFormat::WEBP ) );
	}

	/**
	 * A page from poppler, through `vips copy`, under libvips' cap and
	 * environment; only where vips is there.
	 */
	public function testAPngConverted(): void
	{
		$this->appValues = [ ConfigLexicon::VIPS_PRLIMIT_AS_MB => 2048 ];
		$webp            = substr( $this->output, 0, -4 ) . '.webp';
		$renderer        = $this->renderer( $this->runner( '' ) );

		self::assertTrue( $renderer->canConvert() );
		self::assertTrue( $renderer->convert( $this->output, $webp )->isSuccess() );
		self::assertSame( 'vips', $this->runs[0]['program'] );
		self::assertSame( [ 'copy', $this->output, $webp . '[Q=95]' ], $this->runs[0]['arguments'] );
		self::assertSame( 2048, $this->runs[0]['cap'] );
		self::assertSame( dirname( $webp ), $this->runs[0]['environment']['TMPDIR'] );
		self::assertSame( Outcome::START_FAILED, $renderer->convert( 'page.png', $webp )->outcome );
		self::assertFalse( $this->renderer( $this->runner( '' ), vips: null )->canConvert() );
	}

	/**
	 * Allowed in system config, the variable is not set at all: libvips
	 * blocks where it is set, whatever its value. A value left in app
	 * config, or one in system config that is not true itself, is not read
	 * as allowing them. The threads are a setting.
	 */
	public function testTheUntrustedSwitchAndTheThreads(): void
	{
		$this->appValues    = [
			'vips_block_untrusted'          => false,
			ConfigLexicon::VIPS_CONCURRENCY => 4,
		];
		$this->systemValues = [ ConfigLexicon::ALLOW_UNTRUSTED_SYSTEM_KEY => 'true' ];

		$this->renderer( $this->runner( '' ) )
		     ->render( $this->input, 1024, $this->output )
		;

		self::assertSame( '1', $this->runs[0]['environment']['VIPS_BLOCK_UNTRUSTED'] ?? null );
		self::assertSame( '--vips-concurrency=4', $this->runs[1]['arguments'][3] );

		$this->runs         = [];
		$this->systemValues = [ ConfigLexicon::ALLOW_UNTRUSTED_SYSTEM_KEY => true ];

		$this->renderer( $this->runner( '' ) )
		     ->render( $this->input, 1024, $this->output )
		;

		self::assertArrayNotHasKey( 'VIPS_BLOCK_UNTRUSTED', $this->runs[0]['environment'] );
		self::assertArrayNotHasKey( 'VIPS_BLOCK_UNTRUSTED', $this->runs[1]['environment'] );
	}

	/**
	 * A decoder `vips -l` does not list is missing; an untrusted one is
	 * blocked unless allowed; nothing is known where `vips` did not answer.
	 */
	public function testWhichTypesLibvipsCannotDecode(): void
	{
		$loaders  = [ 'tiffload' => false, 'jp2kload' => true ];
		$renderer = $this->renderer( $this->createMock( CommandRunner::class ), $loaders );

		self::assertNull( $renderer->unsupported( 'image/tiff' ) );
		self::assertEquals( new Unsupported( 'jp2kload', Unsupported::UNTRUSTED ), $renderer->unsupported( 'image/jp2' ) );
		self::assertEquals( new Unsupported( 'heifload', Unsupported::MISSING ), $renderer->unsupported( 'image/heic' ) );

		$this->systemValues = [ ConfigLexicon::ALLOW_UNTRUSTED_SYSTEM_KEY => true ];

		self::assertNull( $this->renderer( $this->createMock( CommandRunner::class ), $loaders )->unsupported( 'image/jp2' ) );
		self::assertNull( $this->renderer( $this->createMock( CommandRunner::class ) )->unsupported( 'image/heic' ) );
	}

	/**
	 * Before libvips 8.13 nothing can be blocked, and libvips picks its
	 * decoder by the content: every image type is unsupported unless
	 * untrusted decoders are allowed. Where vipsthumbnail is not there,
	 * nothing is known, and the render says.
	 */
	public function testEveryImageTypeWhereLibvipsCannotBlock(): void
	{
		$loaders = [ 'tiffload' => false, 'jp2kload' => true ];

		self::assertEquals(
			new Unsupported( 'tiffload', Unsupported::UNBLOCKABLE ),
			$this->renderer( $this->createMock( CommandRunner::class ), $loaders, blockable: false )->unsupported( 'image/tiff' ),
		);
		self::assertNull( $this->renderer( $this->createMock( CommandRunner::class ), $loaders, blockable: null )->unsupported( 'image/tiff' ) );
		self::assertNull( $this->renderer( $this->createMock( CommandRunner::class ), $loaders )->unsupported( 'application/pdf' ) );

		$this->systemValues = [ ConfigLexicon::ALLOW_UNTRUSTED_SYSTEM_KEY => true ];

		self::assertNull( $this->renderer( $this->createMock( CommandRunner::class ), $loaders, blockable: false )->unsupported( 'image/tiff' ) );
	}

	/**
	 * An image smaller than the box keeps its own size; its longer side,
	 * whichever it is.
	 */
	public function testASmallerImageIsNotEnlarged(): void
	{
		$this->renderer( $this->runner( '/dev/fd/4: 1200x3000 uchar, 3 bands, srgb, jpegload' ) )
		     ->render( $this->input, 4096, $this->output )
		;

		self::assertSame( '3000', $this->runs[1]['arguments'][2] );
	}

	/**
	 * A header that cannot be read leaves the box; the thumbnail runs and
	 * says what is wrong.
	 */
	public function testAnUnreadableHeaderLeavesTheBox(): void
	{
		$this->renderer( $this->runner( 'vipsheader: VipsForeignLoad: "/dev/fd/4" is not a known file format', Outcome::FAILED ) )
		     ->render( $this->input, 1024, $this->output )
		;

		self::assertSame( '1024', $this->runs[1]['arguments'][2] );
	}

	/**
	 * The file's name never reaches libvips, so a name ending in `]` is
	 * read as any other; `%` in the output is a format to vipsthumbnail,
	 * not unescaped, and not run.
	 */
	public function testWhatLibvipsWouldMisreadIsNotRun(): void
	{
		$renderer = $this->renderer( $this->runner( '' ) );

		self::assertSame( Outcome::START_FAILED, $renderer->render( $this->input, 1024, '/tmp/out%s/preview.png' )->outcome );
		self::assertSame( [], $this->runs );
		self::assertTrue( $renderer->render( $this->input, 1024, $this->output )->isSuccess() );
		self::assertStringEndsWith( ']', $this->input );
		self::assertSame( '/dev/fd/4', $this->runs[0]['arguments'][0] );
	}

	/**
	 * As measured with libvips 8.15 under tight caps: each message a
	 * failure out of memory, whatever the exit.
	 */
	public function testOutOfMemoryIsNamed(): void
	{
		$messages = [
			[ Outcome::FAILED, 'vips_tracked: out of memory --- size == 1MB' ],
			[ Outcome::FAILED, 'glib: Error creating thread: Resource temporarily unavailable' ],
			[ Outcome::KILLED, '../../../glib/gprintf.c:350: failed to allocate memory' ],
			[ Outcome::FAILED, "error in tile 0 x 0\nTIFFFillStrip: No space for data buffer at scanline 4294967295\ntiff2vips: read error" ],
		];

		foreach ( $messages as [ $result, $stderr ] )
		{
			$runner = $this->createMock( CommandRunner::class );
			$runner->method( 'run' )
			       ->willReturnOnConsecutiveCalls(
				       new RenderOutcome( Outcome::FAILED, 'exit status 1', 1 ),
				       new RenderOutcome( $result, 'failed', 255, null, $stderr ),
			       )
			;

			$outcome = $this->renderer( $runner )->render( $this->input, 1024, $this->output );

			self::assertSame( Outcome::OUT_OF_MEMORY, $outcome->outcome, $stderr );
			self::assertSame( 'out of memory under the address-space cap (vips_prlimit_as_mb)', $outcome->reason );
		}
	}

	/**
	 * libheif crashes under the cap with no word: a crash says the cap may
	 * be why, and stays a crash.
	 */
	public function testACrashPointsToTheCap(): void
	{
		$runner = $this->createMock( CommandRunner::class );
		$runner->method( 'run' )
		       ->willReturnOnConsecutiveCalls(
			       new RenderOutcome( Outcome::SUCCEEDED, 'header', 0, null, '/dev/fd/4: 8000x6000 uchar, 3 bands, srgb, heifload' ),
			       new RenderOutcome( Outcome::KILLED, 'killed by signal 11', null, 11 ),
		       )
		;

		$outcome = $this->renderer( $runner )->render( $this->input, 1024, $this->output );

		self::assertSame( Outcome::KILLED, $outcome->outcome );
		self::assertSame( 'killed by signal 11; under the address-space cap, a crash is often memory running out (vips_prlimit_as_mb)', $outcome->reason );
	}

	public function testSuccessWithoutAFileIsNoOutput(): void
	{
		$runner = $this->createMock( CommandRunner::class );
		$runner->method( 'run' )
		       ->willReturn( new RenderOutcome( Outcome::SUCCEEDED, 'exit status 0', 0 ) )
		;

		self::assertSame( Outcome::NO_OUTPUT, $this->renderer( $runner )->render( $this->input, 1024, $this->output )->outcome );
	}

	public function testAFailurePassesThrough(): void
	{
		$failed = new RenderOutcome( Outcome::FAILED, 'exit status 1', 1, null, 'VipsForeignLoad: "/dev/fd/4" is not a known file format' );
		$runner = $this->createMock( CommandRunner::class );
		$runner->method( 'run' )
		       ->willReturn( $failed )
		;

		self::assertSame( $failed, $this->renderer( $runner )->render( $this->input, 1024, $this->output ) );
	}

	/**
	 * A relative path is refused, and a relative output would land beside
	 * the program; a file that cannot be opened runs nothing.
	 */
	public function testARelativePathIsNotRun(): void
	{
		$runner = $this->createMock( CommandRunner::class );
		$runner->expects( self::never() )
		       ->method( 'run' )
		;

		$renderer = $this->renderer( $runner );

		self::assertSame( Outcome::START_FAILED, $renderer->render( '-o', 100, $this->output )->outcome );
		self::assertSame( Outcome::START_FAILED, $renderer->render( $this->input, 0, $this->output )->outcome );
		self::assertSame( Outcome::START_FAILED, $renderer->render( $this->input, 100, 'out.png' )->outcome );
		self::assertSame( Outcome::START_FAILED, $renderer->render( $this->input, 100, '/tmp/out.gif' )->outcome );
		self::assertSame( 'the file could not be opened', $renderer->render( '/nonexistent/a.tif', 100, $this->output )->reason );
	}

	public function testTheHeaderParsed(): void
	{
		self::assertSame(
			[ 'width' => 22040, 'height' => 12059, 'bands' => 3, 'bytes' => 1, 'loader' => 'tiffload' ],
			VipsRenderer::header( '/dev/fd/4: 22040x12059 uchar, 3 bands, srgb, tiffload' ),
		);
		self::assertSame(
			[ 'width' => 300, 'height' => 600, 'bands' => 1, 'bytes' => 2, 'loader' => 'pngload' ],
			VipsRenderer::header( "warning\n/dev/fd/4: 300x600 ushort, 1 band, grey16, pngload\n" ),
		);
		self::assertSame( 20, VipsRenderer::header( '/dev/fd/4: 10x20 float, 3 bands, scrgb, tiffload' )['height'] ?? null );
		self::assertSame( 4, VipsRenderer::header( '/dev/fd/4: 1x1 float, 3 bands, scrgb, tiffload' )['bytes'] ?? null );
		self::assertSame(
			[ 'width' => 6000, 'height' => 6000, 'bands' => 1, 'bytes' => 4, 'loader' => 'tiffload' ],
			VipsRenderer::header( '/dev/fd/4: 6000x6000 float, 1 band, b-w, tiffload' ),
		);
		self::assertNull( VipsRenderer::header( 'vipsheader: VipsForeignLoad: "/dev/fd/4" is not a known file format' ) );
		self::assertNull( VipsRenderer::header( '/dev/fd/4: 0x0 uchar, 3 bands, srgb, tiffload' ) );
	}

	/**
	 * A TIFF out of memory is re-striped into an uncompressed copy, a file
	 * in the scratch directory unlinked at once and handed on as descriptor
	 * 3, and rendered from it; nothing is left afterwards.
	 */
	public function testATiffOutOfMemoryIsRestripped(): void
	{
		$runner = $this->outOfMemoryOnce( '/dev/fd/4: 15000x15000 uchar, 3 bands, srgb, tiffload' );

		$outcome = $this->renderer( $runner, scratch: $this->scratch( null ) )->render( $this->input, 4096, $this->output );

		self::assertTrue( $outcome->isSuccess(), $outcome->reason );
		self::assertSame( [ 'vipsheader', 'vipsthumbnail', 'tiffcp', 'vipsthumbnail' ], array_column( $this->runs, 'program' ) );
		self::assertSame( [ '-c', 'none', '-r', '64', '/dev/fd/4,0', '/dev/fd/3' ], $this->runs[2]['arguments'] );
		self::assertSame( [ RunOptions::INPUT, RunOptions::COPY ], $this->runs[2]['descriptors'] );
		self::assertSame( 1024, $this->runs[2]['cap'] );
		// The copy's own file-size cap: its pixels and a margin.
		self::assertSame( 15000 * 15000 * 3 + 16_777_216, $this->runs[2]['fsize'] );
		self::assertSame( '/dev/fd/3', $this->runs[3]['arguments'][0] );
		self::assertSame( [ RunOptions::COPY ], $this->runs[3]['descriptors'] );
		self::assertSame( '4096', $this->runs[3]['arguments'][2] );
		self::assertSame( [], glob( dirname( $this->output ) . '/lfp-restrip-*' ) ?: [] );
	}

	/**
	 * Where the copy cannot be made, the render stays out of memory and
	 * says why; another image type, or a TIFF that fails otherwise, gets no
	 * second chance.
	 */
	public function testNoFallbackWhereItCannotHelp(): void
	{
		$tiff = '/dev/fd/4: 15000x15000 uchar, 3 bands, srgb, tiffload';

		$outcome = $this->renderer( $this->outOfMemoryOnce( $tiff ), scratch: $this->scratch( 'the scratch directory /tmp is on tmpfs, in memory' ) )
		                ->render( $this->input, 4096, $this->output )
		;

		self::assertSame( Outcome::OUT_OF_MEMORY, $outcome->outcome );
		self::assertStringEndsWith( '; no one-strip fallback: the scratch directory /tmp is on tmpfs, in memory', $outcome->reason );

		$this->runs = [];
		$this->renderer( $this->outOfMemoryOnce( $tiff ), scratch: $this->scratch( null ), tiffcp: false )->render( $this->input, 4096, $this->output );

		self::assertSame( [ 'vipsheader', 'vipsthumbnail' ], array_column( $this->runs, 'program' ) );

		// Larger than tiff_restrip_max_mb: 15000 x 15000 x 3 is 644 MB.
		$this->appValues = [ ConfigLexicon::TIFF_RESTRIP_MAX_MB => 512 ];
		$this->runs      = [];
		$outcome         = $this->renderer( $this->outOfMemoryOnce( $tiff ), scratch: $this->scratch( null ) )->render( $this->input, 4096, $this->output );

		self::assertStringEndsWith( '; no one-strip fallback: the copy would take 644 MB, more than tiff_restrip_max_mb', $outcome->reason );
		self::assertSame( [ 'vipsheader', 'vipsthumbnail' ], array_column( $this->runs, 'program' ) );

		// Another copy being made, for longer than the wait.
		$this->appValues = [];
		$this->runs      = [];
		$busy            = $this->scratch( null, locked: false );
		$outcome         = $this->renderer( $this->outOfMemoryOnce( $tiff ), scratch: $busy )->render( $this->input, 4096, $this->output );

		self::assertStringEndsWith( '; no one-strip fallback: another one-strip copy is being made', $outcome->reason );

		$this->runs = [];
		$this->renderer( $this->outOfMemoryOnce( '/dev/fd/4: 8000x6000 uchar, 3 bands, srgb, heifload' ), scratch: $this->scratch( null ) )
		     ->render( $this->input, 4096, $this->output )
		;

		self::assertSame( [ 'vipsheader', 'vipsthumbnail' ], array_column( $this->runs, 'program' ) );
	}

	/**
	 * The header, both thumbnails and tiffcp share the render's deadline.
	 */
	public function testOneDeadlineForTheRender(): void
	{
		$this->renderer( $this->outOfMemoryOnce( '/dev/fd/4: 15000x15000 uchar, 3 bands, srgb, tiffload' ), scratch: $this->scratch( null ) )
		     ->render( $this->input, 4096, $this->output )
		;

		self::assertCount( 4, $this->deadlines );
		self::assertInstanceOf( Deadline::class, $this->deadlines[0] );

		foreach ( $this->deadlines as $deadline )
		{
			self::assertSame( $this->deadlines[0], $deadline );
		}
	}

	/**
	 * Where the fallback fails, its outcome is the render's, and its reason
	 * follows the first; a copy stopped by its file-size cap says which.
	 */
	public function testAFailedFallbackKeepsBothReasons(): void
	{
		$tiff    = '/dev/fd/4: 15000x15000 uchar, 3 bands, srgb, tiffload';
		$outcome = $this->renderer( $this->outOfMemoryOnce( $tiff, new RenderOutcome( Outcome::FAILED, 'exit status 1', 1 ) ), scratch: $this->scratch( null ) )
		                ->render( $this->input, 4096, $this->output )
		;

		self::assertSame( Outcome::FAILED, $outcome->outcome );
		self::assertSame( 'out of memory under the address-space cap (vips_prlimit_as_mb); the one-strip fallback: tiffcp: exit status 1', $outcome->reason );

		$this->runs = [];
		$outcome    = $this->renderer( $this->outOfMemoryOnce( $tiff, new RenderOutcome( Outcome::KILLED, 'killed by signal 25', null, 25 ) ), scratch: $this->scratch( null ) )
		                ->render( $this->input, 4096, $this->output )
		;

		self::assertSame( Outcome::KILLED, $outcome->outcome );
		self::assertStringEndsWith( 'tiffcp: a write went past the file-size cap (the copy\'s size, from the header): killed by signal 25', $outcome->reason );
	}

	/**
	 * @param array<string, bool>|null $loaders what `vips -l` answers
	 */
	private function renderer(
		CommandRunner     $runner,
		?array            $loaders = null,
		?string           $vips = '/usr/bin/vips',
		?ScratchDirectory $scratch = null,
		bool              $tiffcp = true,
		?bool             $blockable = true,
	): VipsRenderer
	{
		$listed = $this->createMock( VipsLoaders::class );
		$listed->method( 'loaders' )
		       ->willReturn( $loaders )
		;
		$listed->method( 'canBlockUntrusted' )
		       ->willReturn( $blockable )
		;

		$binaries = $this->createMock( BinaryLocator::class );
		$binaries->method( 'locate' )
		         ->willReturnCallback(
			         static fn(
				         string $program,
			         ): BinaryLocation => new BinaryLocation(
				         $program,
				         match ( $program )
				         {
					         'vips'   => $vips,
					         'tiffcp' => $tiffcp ? '/usr/bin/tiffcp' : null,
					         default  => null,
				         },
				         BinaryLocation::SOURCE_FINDER,
			         ),
		         )
		;

		return new VipsRenderer(
			$runner,
			$this->settings(),
			$listed,
			$binaries,
			$scratch ?? $this->createMock( ScratchDirectory::class ),
		);
	}

	/**
	 * A scratch directory beside the output, with the problem given, and a
	 * lock taken or not.
	 */
	private function scratch(
		?string $problem,
		bool    $locked = true,
	): ScratchDirectory
	{
		$scratch = $this->createMock( ScratchDirectory::class );
		$scratch->method( 'path' )
		        ->willReturn( dirname( $this->output ) )
		;
		$scratch->method( 'problem' )
		        ->willReturn( $problem )
		;
		$scratch->method( 'lock' )
		        ->willReturnCallback( static fn() => $locked ? fopen( 'php://memory', 'rb' ) : null )
		;

		return $scratch;
	}

	/**
	 * A runner whose first thumbnail runs out of memory, as libvips 8.15
	 * says it, and whose tiffcp writes its copy, or ends as given.
	 */
	private function outOfMemoryOnce(
		string         $header,
		?RenderOutcome $tiffcp = null,
	): CommandRunner
	{
		$runner = $this->createMock( CommandRunner::class );
		$runner->method( 'run' )
		       ->willReturnCallback(
			       function(
				       string     $program,
				       array      $arguments,
				       RunOptions $options,
			       ) use (
				       $header,
				       $tiffcp,
			       ): RenderOutcome
			       {
				       /** @var list<string> $arguments */
				       $this->record( $program, $arguments, $options );

				       $thumbnails = count( array_filter( $this->runs, static fn( array $run ): bool => $run['program'] === VipsRenderer::PROGRAM ) );
				       $first      = $program === VipsRenderer::PROGRAM && $thumbnails === 1;

				       return match ( true )
				       {
					       $program === VipsRenderer::HEADER                         => new RenderOutcome( Outcome::SUCCEEDED, 'header', 0, null, $header ),
					       $first                                                    => new RenderOutcome( Outcome::FAILED, 'exit status 255', 255, null, 'vips_tracked: out of memory --- size == 1MB' ),
					       $program === VipsRenderer::RESTRIPPER && $tiffcp !== null => $tiffcp,
					       default                                                   => self::written( $arguments, $options ),
				       };
			       },
		       )
		;

		return $runner;
	}

	/**
	 * @param list<string> $arguments
	 */
	private function record(
		string     $program,
		array      $arguments,
		RunOptions $options,
	): void
	{
		$this->runs[] = [
			'program'     => $program,
			'arguments'   => $arguments,
			'keepStdout'  => $options->keepStdout,
			'environment' => $options->environment,
			'cap'         => $options->prlimitAsMb,
			'descriptors' => array_keys( $options->descriptors ),
			'fsize'       => $options->fsizeBytes,
		];

		$this->deadlines[] = $options->deadline;
	}


//  config/init/exe/run methods

	/**
	 * A runner that records each run, answers the header with what is
	 * given, and writes the thumbnail.
	 */
	private function runner(
		string  $header,
		Outcome $headerOutcome = Outcome::SUCCEEDED,
	): CommandRunner
	{
		$runner = $this->createMock( CommandRunner::class );
		$runner->method( 'run' )
		       ->willReturnCallback(
			       function(
				       string     $program,
				       array      $arguments,
				       RunOptions $options,
			       ) use (
				       $header,
				       $headerOutcome,
			       ): RenderOutcome
			       {
				       /** @var list<string> $arguments */
				       $this->record( $program, $arguments, $options );

				       return $program === VipsRenderer::HEADER
					       ? new RenderOutcome( $headerOutcome, 'header', 0, null, $header )
					       : self::written( $arguments, $options );
			       },
		       )
		;

		return $runner;
	}


//  static methods

	/**
	 * Success, with what a program was given to write written: the copy's
	 * descriptor, or the file named last, its save options cut.
	 *
	 * @param list<string> $arguments
	 */
	private static function written(
		array      $arguments,
		RunOptions $options,
	): RenderOutcome
	{
		$last = (string) end( $arguments );

		if ( $last === RunOptions::path( RunOptions::COPY ) )
		{
			fwrite( $options->descriptors[ RunOptions::COPY ], 'copy' );
		}
		else
		{
			touch( (string) preg_replace( '/\[[^]]*]$/', '', $last ) );
		}

		return new RenderOutcome( Outcome::SUCCEEDED, 'exit status 0', 0 );
	}
}
