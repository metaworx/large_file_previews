<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Integration;

/**
 * A small valid PDF, written here rather than kept as a file: one page of
 * the given size in points, a red rectangle on white, with a correct
 * cross-reference table, which is what pdftoppm reads first.
 *
 * An Illustrator file of version 9 or later is a PDF inside, so the same
 * bytes stand for one under an `.ai` name.
 */
final class PdfFixture
{

//  static methods

	public static function bytes(
		int $width = 400,
		int $height = 200,
	): string
	{
		$content = sprintf( "1 0 0 rg\n%d %d %d %d re f\n", (int) ( $width / 4 ), (int) ( $height / 4 ), (int) ( $width / 2 ), (int) ( $height / 2 ) );
		$objects = [
			'<< /Type /Catalog /Pages 2 0 R >>',
			'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
			sprintf( '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %d %d] /Contents 4 0 R /Resources << >> >>', $width, $height ),
			sprintf( "<< /Length %d >>\nstream\n%sendstream", strlen( $content ), $content ),
		];

		$pdf     = "%PDF-1.4\n";
		$offsets = [];

		foreach ( $objects as $index => $object )
		{
			$offsets[] = strlen( $pdf );
			$pdf .= sprintf( "%d 0 obj\n%s\nendobj\n", $index + 1, $object );
		}

		$xref = strlen( $pdf );
		$pdf .= sprintf( "xref\n0 %d\n0000000000 65535 f \n", count( $objects ) + 1 );

		foreach ( $offsets as $offset )
		{
			$pdf .= sprintf( "%010d 00000 n \n", $offset );
		}

		return $pdf . sprintf( "trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n", count( $objects ) + 1, $xref );
	}
}
