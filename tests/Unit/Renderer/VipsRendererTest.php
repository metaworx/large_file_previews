<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Unit\Renderer;

use OCA\LargeFilePreviews\Config\ConfigLexicon;
use OCA\LargeFilePreviews\Renderer\VipsRenderer;
use OCA\LargeFilePreviews\Service\CommandRunner;
use OCA\LargeFilePreviews\Service\ConfigurationService;
use OCA\LargeFilePreviews\Service\Outcome;
use OCA\LargeFilePreviews\Service\RenderOutcome;
use OCA\LargeFilePreviews\Tests\Unit\EnglishL10n;
use OCA\LargeFilePreviews\Tests\Unit\MemoryAppConfig;
use PHPUnit\Framework\TestCase;

class VipsRendererTest
    extends
    TestCase
{
	use EnglishL10n;
	use MemoryAppConfig;


//  private properties

	private string $output;

	/** @var list<array{program: string, arguments: list<string>, keepStdout: bool, environment: array<string, string>, cap: ?int}> */
	private array $runs = [];


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->output = sys_get_temp_dir() . '/lfp-vips-' . bin2hex( random_bytes( 4 ) ) . '/preview.png';
	}


//  other non-static methods

	protected function tearDown(): void
	{
		@unlink( $this->output );
		@rmdir( dirname( $this->output ) );

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
	 * The header, then the thumbnail: each with libvips' cap, two arenas,
	 * untrusted decoders blocked and the spill in the render folder.
	 */
	public function testTheCommands(): void
	{
		$this->appValues = [ ConfigLexicon::VIPS_PRLIMIT_AS_MB => 2048 ];

		$outcome = $this->renderer( $this->runner( '/data/a b.tif: 22040x12059 uchar, 3 bands, srgb, tiffload' ) )
		                ->render( '/data/a b.tif', 4096, $this->output )
		;

		self::assertTrue( $outcome->isSuccess(), $outcome->reason );

		$environment = [
			'VIPS_BLOCK_UNTRUSTED' => '1',
			'MALLOC_ARENA_MAX'     => '2',
			'TMPDIR'               => dirname( $this->output ),
		];

		self::assertSame(
			[
				[
					'program'     => 'vipsheader',
					'arguments'   => [ '/data/a b.tif' ],
					'keepStdout'  => true,
					'environment' => $environment,
					'cap'         => 2048,
				],
				[
					'program'     => 'vipsthumbnail',
					'arguments'   => [ '/data/a b.tif', '--size', '4096', '--vips-concurrency=1', '-o', $this->output ],
					'keepStdout'  => false,
					'environment' => $environment,
					'cap'         => 2048,
				],
			],
			$this->runs,
		);
	}

	/**
	 * An image smaller than the box keeps its own size; its longer side,
	 * whichever it is.
	 */
	public function testASmallerImageIsNotEnlarged(): void
	{
		$this->renderer( $this->runner( '/data/a.jpg: 1200x3000 uchar, 3 bands, srgb, jpegload' ) )
		     ->render( '/data/a.jpg', 4096, $this->output )
		;

		self::assertSame( '3000', $this->runs[1]['arguments'][2] );
	}

	/**
	 * A header that cannot be read leaves the box; the thumbnail runs and
	 * says what is wrong.
	 */
	public function testAnUnreadableHeaderLeavesTheBox(): void
	{
		$this->renderer( $this->runner( 'vipsheader: VipsForeignLoad: "/data/a.tif" is not a known file format', Outcome::FAILED ) )
		     ->render( '/data/a.tif', 1024, $this->output )
		;

		self::assertSame( '1024', $this->runs[1]['arguments'][2] );
	}

	/**
	 * As found with libvips 8.15: a name ending in `]` cannot be opened,
	 * and `%%` in the output is not unescaped. Neither is run; brackets
	 * inside a name are.
	 */
	public function testWhatLibvipsWouldMisreadIsNotRun(): void
	{
		$renderer = $this->renderer( $this->runner( '' ) );

		self::assertSame( Outcome::START_FAILED, $renderer->render( '/data/b[x]', 1024, $this->output )->outcome );
		self::assertSame( Outcome::START_FAILED, $renderer->render( '/data/a.tif', 1024, '/tmp/out%s/preview.png' )->outcome );
		self::assertSame( [], $this->runs );
		self::assertTrue( $renderer->render( '/data/a [1].tif', 1024, $this->output )->isSuccess() );
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

			$outcome = $this->renderer( $runner )->render( '/data/a.tif', 1024, $this->output );

			self::assertSame( Outcome::OUT_OF_MEMORY, $outcome->outcome, $stderr );
			self::assertSame( 'out of memory under the address-space cap (vips_prlimit_as_mb)', $outcome->reason );
		}
	}

	public function testSuccessWithoutAFileIsNoOutput(): void
	{
		$runner = $this->createMock( CommandRunner::class );
		$runner->method( 'run' )
		       ->willReturn( new RenderOutcome( Outcome::SUCCEEDED, 'exit status 0', 0 ) )
		;

		self::assertSame( Outcome::NO_OUTPUT, $this->renderer( $runner )->render( '/data/a.tif', 1024, $this->output )->outcome );
	}

	public function testAFailurePassesThrough(): void
	{
		$failed = new RenderOutcome( Outcome::FAILED, 'exit status 1', 1, null, 'VipsForeignLoad: "/data/a.jp2" is not a known file format' );
		$runner = $this->createMock( CommandRunner::class );
		$runner->method( 'run' )
		       ->willReturn( $failed )
		;

		self::assertSame( $failed, $this->renderer( $runner )->render( '/data/a.jp2', 1024, $this->output ) );
	}

	/**
	 * A relative path could be read as an option, and a relative output
	 * would land beside the input.
	 */
	public function testARelativePathIsNotRun(): void
	{
		$runner = $this->createMock( CommandRunner::class );
		$runner->expects( self::never() )
		       ->method( 'run' )
		;

		$renderer = $this->renderer( $runner );

		self::assertSame( Outcome::START_FAILED, $renderer->render( '-o', 100, $this->output )->outcome );
		self::assertSame( Outcome::START_FAILED, $renderer->render( '/in.tif', 0, $this->output )->outcome );
		self::assertSame( Outcome::START_FAILED, $renderer->render( '/in.tif', 100, 'out.png' )->outcome );
		self::assertSame( Outcome::START_FAILED, $renderer->render( '/in.tif', 100, '/tmp/out.jpg' )->outcome );
	}

	public function testTheLongerSideFromTheHeader(): void
	{
		self::assertSame( 22040, VipsRenderer::longerSide( '/a/b.tif: 22040x12059 uchar, 3 bands, srgb, tiffload' ) );
		self::assertSame( 600, VipsRenderer::longerSide( "warning\n/a/b.png: 300x600 ushort, 1 band, grey16, pngload\n" ) );
		// The path may look like a header; the last field on the line counts.
		self::assertSame( 20, VipsRenderer::longerSide( '/a/b: 9x9 x, 3 bands, c.tif: 10x20 uchar, 3 bands, srgb, tiffload' ) );
		self::assertNull( VipsRenderer::longerSide( 'vipsheader: VipsForeignLoad: "/a.tif" is not a known file format' ) );
		self::assertNull( VipsRenderer::longerSide( '/a.tif: 0x0 uchar, 3 bands, srgb, tiffload' ) );
	}

	private function renderer( CommandRunner $runner ): VipsRenderer
	{
		return new VipsRenderer(
			$runner,
			new ConfigurationService( $this->memoryAppConfig(), $this->englishL10n(), $this->mimeDetector() ),
		);
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
				       string $program,
				       array  $arguments,
				       bool   $keepStdout = false,
				       array  $environment = [],
				       ?int   $cap = null,
			       ) use (
				       $header,
				       $headerOutcome,
			       ): RenderOutcome
			       {
				       /** @var list<string> $arguments */
				       /** @var array<string, string> $environment */
				       $this->runs[] = [
					       'program'     => $program,
					       'arguments'   => $arguments,
					       'keepStdout'  => $keepStdout,
					       'environment' => $environment,
					       'cap'         => $cap,
				       ];

				       if ( $program === VipsRenderer::HEADER )
				       {
					       return new RenderOutcome( $headerOutcome, 'header', 0, null, $header );
				       }

				       @mkdir( dirname( $this->output ) );
				       touch( $this->output );

				       return new RenderOutcome( Outcome::SUCCEEDED, 'exit status 0', 0 );
			       },
		       )
		;

		return $runner;
	}
}
