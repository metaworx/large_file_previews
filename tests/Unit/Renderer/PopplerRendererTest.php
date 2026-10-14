<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Unit\Renderer;

use OCA\LargeFilePreviews\Renderer\PageProbe;
use OCA\LargeFilePreviews\Renderer\PopplerRenderer;
use OCA\LargeFilePreviews\Renderer\Renderers;
use OCA\LargeFilePreviews\Service\CommandRunner;
use OCA\LargeFilePreviews\Service\Outcome;
use OCA\LargeFilePreviews\Service\RenderOutcome;
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
		@unlink( $this->output );

		parent::tearDown();
	}

	public function testItHandlesPdfAndIllustratorOnly(): void
	{
		$renderer = new PopplerRenderer( $this->createMock( CommandRunner::class ), $this->probe( null ) );

		self::assertTrue( $renderer->supports( 'application/pdf' ) );
		self::assertTrue( $renderer->supports( 'application/illustrator' ) );
		self::assertFalse( $renderer->supports( 'image/tiff' ) );
		self::assertFalse( $renderer->supports( 'application/postscript' ) );
	}

	/**
	 * Page 1 only, fitted into the box, to exactly `<stem>.png`.
	 */
	public function testTheCommand(): void
	{
		$runner = $this->renders( 4096 );

		self::assertTrue(
			( new PopplerRenderer( $runner, $this->probe( null ) ) )
				->render( '/data/alice/files/a b.pdf', 4096, $this->output )
				->isSuccess(),
		);
	}

	/**
	 * A 300 dpi A4 scan is 3508 pixels tall: not enlarged to 4096.
	 */
	public function testAScanIsRenderedAtItsOwnSize(): void
	{
		$runner = $this->renders( 3508 );

		self::assertTrue(
			( new PopplerRenderer( $runner, $this->probe( 3508 ) ) )
				->render( '/data/alice/files/a b.pdf', 4096, $this->output )
				->isSuccess(),
		);
	}

	/**
	 * A scan larger than the box is fitted into the box, as any page is.
	 */
	public function testALargerScanKeepsTheBox(): void
	{
		$runner = $this->renders( 4096 );

		self::assertTrue(
			( new PopplerRenderer( $runner, $this->probe( 7016 ) ) )
				->render( '/data/alice/files/a b.pdf', 4096, $this->output )
				->isSuccess(),
		);
	}

	public function testSuccessWithoutAFileIsNoOutput(): void
	{
		$runner = $this->createMock( CommandRunner::class );
		$runner->method( 'run' )
		       ->willReturn( new RenderOutcome( Outcome::SUCCEEDED, 'exit status 0', 0, null, 'Syntax Warning: x' ) )
		;

		$outcome = ( new PopplerRenderer( $runner, $this->probe( null ) ) )->render( '/in.pdf', 100, $this->output );

		self::assertSame( Outcome::NO_OUTPUT, $outcome->outcome );
		self::assertSame( 0, $outcome->exitStatus );
		self::assertSame( 'Syntax Warning: x', $outcome->stderr );
	}

	public function testAFailurePassesThrough(): void
	{
		$failed = new RenderOutcome( Outcome::FAILED, 'exit status 1', 1 );
		$runner = $this->createMock( CommandRunner::class );
		$runner->method( 'run' )
		       ->willReturn( $failed )
		;

		self::assertSame( $failed, ( new PopplerRenderer( $runner, $this->probe( null ) ) )->render( '/in.pdf', 100, $this->output ) );
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

		$renderer = new PopplerRenderer( $runner, $probe );

		self::assertSame( Outcome::START_FAILED, $renderer->render( '-opw', 100, $this->output )->outcome );
		self::assertSame( Outcome::START_FAILED, $renderer->render( '/in.pdf', 0, $this->output )->outcome );
		self::assertSame( Outcome::START_FAILED, $renderer->render( '/in.pdf', 100, '/tmp/out.jpg' )->outcome );
	}

	public function testRenderersFindByType(): void
	{
		$poppler   = new PopplerRenderer( $this->createMock( CommandRunner::class ), $this->probe( null ) );
		$renderers = new Renderers( $poppler );

		self::assertSame( $poppler, $renderers->for( 'application/pdf' ) );
		self::assertNull( $renderers->for( 'image/tiff' ) );
	}

	/**
	 * A runner that expects pdftoppm once, with the box given, and writes
	 * the PNG.
	 */
	private function renders( int $box ): CommandRunner
	{
		$runner = $this->createMock( CommandRunner::class );
		$runner->expects( self::once() )
		       ->method( 'run' )
		       ->with(
			       'pdftoppm',
			       [ '-f', '1', '-l', '1', '-scale-to', (string) $box, '-png', '-singlefile', '/data/alice/files/a b.pdf', substr( $this->output, 0, -4 ) ],
		       )
		       ->willReturnCallback(
			       function(): RenderOutcome
			       {
				       touch( $this->output );

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
}
