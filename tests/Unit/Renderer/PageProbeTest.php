<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Unit\Renderer;

use OCA\LargeFilePreviews\Renderer\PageProbe;
use OCA\LargeFilePreviews\Service\CommandRunner;
use OCA\LargeFilePreviews\Service\Outcome;
use OCA\LargeFilePreviews\Service\RenderOutcome;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PageProbeTest
    extends
    TestCase
{

//  constants

	private const HEADER = "page   num  type   width height color comp bpc  enc interp  object ID x-ppi y-ppi size ratio\n"
		. "--------------------------------------------------------------------------------------------\n";

	private const A4 = [ 595.276, 841.89 ];


//  other non-static methods

	public function testThePageSizeIsReadFromPdfinfo(): void
	{
		self::assertSame( [ 595.276, 841.89 ], PageProbe::pageSize( "Pages:           1\nPage    1 size:  595.276 x 841.89 pts (A4)\nPage    1 rot:   0\n" ) );
		self::assertNull( PageProbe::pageSize( "Pages: 1\n" ) );
	}

	public function testImagesAreReadAndMasksLeftOut(): void
	{
		$listing = self::HEADER
			. "   1     0 image    2480  3508  gray    1   1  ccitt  no         4  0   300   300 54.2K 5.1%\n"
			. "   1     1 smask    2480  3508  gray    1   8  image  no         5  0   300   300  1.2K 0.1%\n";

		self::assertSame( [ [ 'width' => 2480, 'height' => 3508, 'xPpi' => 300, 'yPpi' => 300 ] ], PageProbe::images( $listing ) );
		self::assertSame( [], PageProbe::images( self::HEADER ) );
		self::assertNull( PageProbe::images( 'Syntax Error: something' ) );
		self::assertNull( PageProbe::images( self::HEADER . "   1  0 image 12\n" ) );
	}

	/**
	 * @param list<array{width: int, height: int, xPpi: int, yPpi: int}>|null $images
	 */
	#[DataProvider( 'pages' )]
	public function testTheBox(
		?array $images,
		?int   $expected,
	): void
	{
		self::assertSame( $expected, PageProbe::box( self::A4, $images ) );
	}

	public function testScanBoxRunsBothProgramsOnPageOne(): void
	{
		$runner = $this->createMock( CommandRunner::class );
		$runner->expects( self::exactly( 2 ) )
		       ->method( 'run' )
		       ->willReturnCallback(
			       static fn(
				       string $program,
				       array  $arguments,
				       bool   $keepStdout,
			       ): RenderOutcome => match ( $program )
			       {
				       'pdfinfo'   => new RenderOutcome( Outcome::SUCCEEDED, '', 0, null, "Page    1 size:  595.276 x 841.89 pts (A4)\n" ),
				       'pdfimages' => new RenderOutcome(
					       Outcome::SUCCEEDED,
					       '',
					       0,
					       null,
					       self::HEADER . "   1     0 image    2480  3508  gray    1   1  ccitt  no         4  0   300   300 54.2K 5.1%\n",
				       ),
			       },
		       )
		;

		self::assertSame( 3508, ( new PageProbe( $runner ) )->scanBox( '/data/scan.pdf' ) );
	}

	public function testAFailedProbeMeansNoScan(): void
	{
		$runner = $this->createMock( CommandRunner::class );
		$runner->expects( self::once() )
		       ->method( 'run' )
		       ->willReturn( new RenderOutcome( Outcome::PROGRAM_MISSING, 'pdfinfo not found' ) )
		;

		self::assertNull( ( new PageProbe( $runner ) )->scanBox( '/data/scan.pdf' ) );
	}


//  static methods

	/**
	 * @return array<string, array{list<array{width: int, height: int, xPpi: int, yPpi: int}>|null, ?int}>
	 */
	public static function pages(): array
	{
		$scan = [ 'width' => 2480, 'height' => 3508, 'xPpi' => 300, 'yPpi' => 300 ];

		return [
			'a 300 dpi A4 scan'                    => [ [ $scan ], 3508 ],
			'a 600 dpi scan, larger than any box'  => [ [ [ 'width' => 4960, 'height' => 7016, 'xPpi' => 600, 'yPpi' => 600 ] ], 7016 ],
			'a scan turned on its side'            => [ [ [ 'width' => 3508, 'height' => 2480, 'xPpi' => 300, 'yPpi' => 300 ] ], 3508 ],
			'a scan with a small margin'           => [ [ [ 'width' => 2300, 'height' => 3300, 'xPpi' => 300, 'yPpi' => 300 ] ], 3508 ],
			'a logo on a page of text'             => [ [ [ 'width' => 300, 'height' => 100, 'xPpi' => 72, 'yPpi' => 72 ] ], null ],
			'two images'                           => [ [ $scan, $scan ], null ],
			'no image: a vector page'              => [ [], null ],
			'a listing that could not be read'     => [ null, null ],
			'an image without a resolution'        => [ [ [ 'width' => 2480, 'height' => 3508, 'xPpi' => 0, 'yPpi' => 0 ] ], null ],
		];
	}
}
