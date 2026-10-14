<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Unit\Renderer;

use OCA\LargeFilePreviews\Renderer\PageProbe;
use OCA\LargeFilePreviews\Renderer\PopplerRenderer;
use OCA\LargeFilePreviews\Renderer\PreviewFormat;
use OCA\LargeFilePreviews\Renderer\Renderers;
use OCA\LargeFilePreviews\Renderer\VipsRenderer;
use OCA\LargeFilePreviews\Service\CommandRunner;
use OCA\LargeFilePreviews\Service\Outcome;
use OCA\LargeFilePreviews\Service\RenderOutcome;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PopplerRendererTest
    extends
    TestCase
{

//  private properties

	private string $output;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->output = sys_get_temp_dir() . '/lfp-poppler-' . bin2hex( random_bytes( 4 ) ) . '.png';
	}


//  other non-static methods

	protected function tearDown(): void
	{
		foreach ( [ '.png', '.jpg', '.webp' ] as $extension )
		{
			@unlink( substr( $this->output, 0, (int) strrpos( $this->output, '.' ) ) . $extension );
		}

		parent::tearDown();
	}

	public function testItHandlesPdfAndIllustratorOnly(): void
	{
		$renderer = $this->poppler( $this->createMock( CommandRunner::class ) );

		self::assertTrue( $renderer->supports( 'application/pdf' ) );
		self::assertTrue( $renderer->supports( 'application/illustrator' ) );
		self::assertFalse( $renderer->supports( 'image/tiff' ) );
		self::assertFalse( $renderer->supports( 'application/postscript' ) );
		self::assertNull( $renderer->unsupported( 'application/pdf' ) );
	}

	/**
	 * Page 1 only, fitted into the box, to exactly `<stem>.png`.
	 */
	public function testTheCommand(): void
	{
		$runner = $this->renders( 4096, [ '-png' ] );

		self::assertTrue(
			$this->poppler( $runner )
			     ->render( '/data/alice/files/a b.pdf', 4096, $this->output )
			     ->isSuccess(),
		);
	}

	/**
	 * A JPEG is pdftoppm's own, at the quality the renderers share.
	 */
	public function testAJpeg(): void
	{
		$this->output = substr( $this->output, 0, -4 ) . '.jpg';
		$runner       = $this->renders( 4096, [ '-jpeg', '-jpegopt', 'quality=95' ] );

		self::assertTrue(
			$this->poppler( $runner )
			     ->render( '/data/alice/files/a b.pdf', 4096, $this->output )
			     ->isSuccess(),
		);
	}

	/**
	 * A WebP is pdftoppm's PNG converted by libvips; without libvips, the
	 * format asked for becomes JPEG.
	 */
	public function testAWebpThroughLibvips(): void
	{
		$webp   = substr( $this->output, 0, -4 ) . '.webp';
		$runner = $this->renders( 4096, [ '-png' ] );
		$vips   = $this->vips( true );
		$vips->expects( self::once() )
		     ->method( 'convert' )
		     ->with( $this->output, $webp )
		     ->willReturn( new RenderOutcome( Outcome::SUCCEEDED, 'exit status 0', 0 ) )
		;

		$renderer = new PopplerRenderer( $runner, $this->probe( null ), $vips );

		self::assertSame( PreviewFormat::WEBP, $renderer->format( PreviewFormat::WEBP ) );
		self::assertTrue( $renderer->render( '/data/alice/files/a b.pdf', 4096, $webp )->isSuccess() );

		$without = $this->poppler( $this->createMock( CommandRunner::class ) );

		self::assertSame( PreviewFormat::JPEG, $without->format( PreviewFormat::WEBP ) );
		self::assertSame( PreviewFormat::PNG, $without->format( PreviewFormat::PNG ) );
	}

	/**
	 * A 300 dpi A4 scan is 3508 pixels tall: not enlarged to 4096.
	 */
	public function testAScanIsRenderedAtItsOwnSize(): void
	{
		$runner = $this->renders( 3508, [ '-png' ] );

		self::assertTrue(
			$this->poppler( $runner, 3508 )
			     ->render( '/data/alice/files/a b.pdf', 4096, $this->output )
			     ->isSuccess(),
		);
	}

	/**
	 * A scan larger than the box is fitted into the box, as any page is.
	 */
	public function testALargerScanKeepsTheBox(): void
	{
		$runner = $this->renders( 4096, [ '-png' ] );

		self::assertTrue(
			$this->poppler( $runner, 7016 )
			     ->render( '/data/alice/files/a b.pdf', 4096, $this->output )
			     ->isSuccess(),
		);
	}

	/**
	 * As measured with poppler 24.02 under a 64 MB cap: "Out of memory",
	 * exit 0, and a PNG of 90 bytes.
	 */
	public function testOutOfMemoryWithExitZeroIsAFailure(): void
	{
		$runner = $this->createMock( CommandRunner::class );
		$runner->method( 'run' )
		       ->willReturnCallback(
			       function(): RenderOutcome
			       {
				       file_put_contents( $this->output, str_repeat( 'x', 90 ) );

				       return new RenderOutcome( Outcome::SUCCEEDED, 'exit status 0', 0, null, "Syntax Warning: hints\nOut of memory" );
			       },
		       )
		;

		$outcome = $this->poppler( $runner )->render( '/in.pdf', 4096, $this->output );

		self::assertSame( Outcome::OUT_OF_MEMORY, $outcome->outcome );
		self::assertSame( 'out of memory under the address-space cap (prlimit_as_mb)', $outcome->reason );
		self::assertFalse( $outcome->isSuccess() );
	}

	public function testSuccessWithoutAFileIsNoOutput(): void
	{
		$runner = $this->createMock( CommandRunner::class );
		$runner->method( 'run' )
		       ->willReturn( new RenderOutcome( Outcome::SUCCEEDED, 'exit status 0', 0, null, 'Syntax Warning: x' ) )
		;

		$outcome = $this->poppler( $runner )->render( '/in.pdf', 100, $this->output );

		self::assertSame( Outcome::NO_OUTPUT, $outcome->outcome );
		self::assertSame( 0, $outcome->exitStatus );
		self::assertSame( 'Syntax Warning: x', $outcome->stderr );
	}

	/**
	 * A failure is not converted.
	 */
	public function testAFailurePassesThrough(): void
	{
		$failed = new RenderOutcome( Outcome::FAILED, 'exit status 1', 1 );
		$runner = $this->createMock( CommandRunner::class );
		$runner->method( 'run' )
		       ->willReturn( $failed )
		;
		$vips = $this->vips( true );
		$vips->expects( self::never() )
		     ->method( 'convert' )
		;

		self::assertSame( $failed, ( new PopplerRenderer( $runner, $this->probe( null ), $vips ) )->render( '/in.pdf', 100, '/tmp/out.webp' ) );
	}

	/**
	 * A relative path could be read as an option; it never reaches a
	 * program, the probe's included.
	 */
	public function testARelativePathIsNotRun(): void
	{
		$runner = $this->createMock( CommandRunner::class );
		$runner->expects( self::never() )
		       ->method( 'run' )
		;
		$probe = $this->createMock( PageProbe::class );
		$probe->expects( self::never() )
		      ->method( 'scanBox' )
		;

		$renderer = new PopplerRenderer( $runner, $probe, $this->vips( false ) );

		self::assertSame( Outcome::START_FAILED, $renderer->render( '-opw', 100, $this->output )->outcome );
		self::assertSame( Outcome::START_FAILED, $renderer->render( '/in.pdf', 0, $this->output )->outcome );
		self::assertSame( Outcome::START_FAILED, $renderer->render( '/in.pdf', 100, '/tmp/out.gif' )->outcome );
		self::assertSame( Outcome::START_FAILED, $renderer->render( '/in.pdf', 100, 'out.png' )->outcome );
	}

	public function testRenderersFindByType(): void
	{
		$vips      = $this->vips( false );
		$poppler   = new PopplerRenderer( $this->createMock( CommandRunner::class ), $this->probe( null ), $vips );
		$renderers = new Renderers( $poppler, $vips );

		self::assertSame( $poppler, $renderers->for( 'application/pdf' ) );
		self::assertSame( $vips, $renderers->for( 'image/tiff' ) );
		self::assertNull( $renderers->for( 'application/x-7z-compressed' ) );
	}

	private function poppler(
		CommandRunner $runner,
		?int          $scanBox = null,
	): PopplerRenderer
	{
		return new PopplerRenderer( $runner, $this->probe( $scanBox ), $this->vips( false ) );
	}

	/**
	 * A runner that expects pdftoppm once, with the box and the format's
	 * options given, and writes its file.
	 *
	 * @param list<string> $format
	 */
	private function renders(
		int   $box,
		array $format,
	): CommandRunner
	{
		$stem   = substr( $this->output, 0, (int) strrpos( $this->output, '.' ) );
		$runner = $this->createMock( CommandRunner::class );
		$runner->expects( self::once() )
		       ->method( 'run' )
		       ->with(
			       'pdftoppm',
			       [ '-f', '1', '-l', '1', '-scale-to', (string) $box, ...$format, '-singlefile', '/data/alice/files/a b.pdf', $stem ],
		       )
		       ->willReturnCallback(
			       static function() use (
				       $stem,
				       $format,
			       ): RenderOutcome
			       {
				       touch( $stem . ( $format[0] === '-jpeg' ? '.jpg' : '.png' ) );

				       return new RenderOutcome( Outcome::SUCCEEDED, 'exit status 0', 0 );
			       },
		       )
		;

		return $runner;
	}

	private function probe( ?int $scanBox ): PageProbe
	{
		$probe = $this->createMock( PageProbe::class );
		$probe->method( 'scanBox' )
		      ->willReturn( $scanBox )
		;

		return $probe;
	}

	private function vips( bool $canConvert ): VipsRenderer&MockObject
	{
		$vips = $this->createMock( VipsRenderer::class );
		$vips->method( 'canConvert' )
		     ->willReturn( $canConvert )
		;
		$vips->method( 'supports' )
		     ->willReturnCallback( static fn( string $mime ): bool => str_starts_with( $mime, 'image/' ) )
		;

		return $vips;
	}
}
