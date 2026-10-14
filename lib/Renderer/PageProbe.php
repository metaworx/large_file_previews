<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Renderer;

use OCA\LargeFilePreviews\Config\ConfigLexicon;
use OCA\LargeFilePreviews\Service\CommandRunner;
use OCA\LargeFilePreviews\Service\ConfigurationService;

/**
 * Whether page 1 of a PDF is a scan, and if so its size in pixels at the
 * scan's own resolution.
 *
 * Rendering a 300 dpi A4 scan into a 4096 pixel box enlarges it: a larger
 * preview, no more detail. A page is a scan where it holds exactly one image
 * (masks aside) that covers the page each way to the share
 * `scan_coverage_percent` sets, 90 % by default; a vector page, text with a
 * logo, or several images are not. `scan_native_enabled` switches the whole
 * question off. Two programs from poppler-utils answer, each reading page 1
 * only:
 *
 *     pdfinfo -f 1 -l 1 <input>          Page    1 size:  595.276 x 841.89 pts (A4)
 *     pdfimages -list -f 1 -l 1 <input>  ... image 2480 3508 gray ... 300 300 ...
 *
 * Any doubt - a probe that fails, a program that is missing, output that is
 * not as expected - answers null, and the page keeps the full box.
 */
class PageProbe
{

//  constants

	public const PDFINFO = 'pdfinfo';

	public const PDFIMAGES = 'pdfimages';


//  constructor

	public function __construct(
		private readonly CommandRunner        $runner,
		private readonly ConfigurationService $config,
	)
	{
	}


//  other non-static methods

	/**
	 * The longer side, in pixels, of page 1 at its scan's resolution, or
	 * null where page 1 is not a scan, cannot be told, or is not asked.
	 */
	public function scanBox( string $localPath ): ?int
	{
		if ( $this->config->getScalar( ConfigLexicon::SCAN_NATIVE_ENABLED ) !== true )
		{
			return null;
		}

		$info = $this->runner->run( self::PDFINFO, [ '-f', '1', '-l', '1', $localPath ], true );

		if ( ! $info->isSuccess() )
		{
			return null;
		}

		$page = self::pageSize( $info->stderr );

		if ( $page === null )
		{
			return null;
		}

		$images = $this->runner->run( self::PDFIMAGES, [ '-list', '-f', '1', '-l', '1', $localPath ], true );

		if ( ! $images->isSuccess() )
		{
			return null;
		}

		return self::box(
			$page,
			self::images( $images->stderr ),
			(float) $this->config->getScalar( ConfigLexicon::SCAN_COVERAGE_PERCENT ) / 100.0,
		);
	}


//  static methods

	/**
	 * Page 1's width and height in points, from pdfinfo.
	 *
	 * @return array{float, float}|null
	 */
	public static function pageSize( string $pdfinfo ): ?array
	{
		if ( preg_match( '/^Page\s+1 size:\s+([\d.]+) x ([\d.]+) pts/m', $pdfinfo, $match ) !== 1 )
		{
			return null;
		}

		$width  = (float) $match[1];
		$height = (float) $match[2];

		return $width > 0 && $height > 0
			? [ $width, $height ]
			: null;
	}

	/**
	 * The images of page 1, masks left out, from pdfimages -list: each with
	 * its size in pixels and its resolution.
	 *
	 * Rows follow a line of dashes; the columns are page, num, type, width,
	 * height, color, comp, bpc, enc, interp, object, ID, x-ppi, y-ppi, size,
	 * ratio.
	 *
	 * @return list<array{width: int, height: int, xPpi: int, yPpi: int}>|null null where the listing cannot be read
	 */
	public static function images( string $listing ): ?array
	{
		$lines = preg_split( '/\R/', trim( $listing ) ) ?: [];
		$start = null;

		foreach ( $lines as $index => $line )
		{
			if ( preg_match( '/^-{20,}$/', trim( $line ) ) === 1 )
			{
				$start = $index + 1;

				break;
			}
		}

		if ( $start === null )
		{
			return null;
		}

		$images = [];

		foreach ( array_slice( $lines, $start ) as $line )
		{
			$columns = preg_split( '/\s+/', trim( $line ) ) ?: [];

			if ( count( $columns ) < 14 )
			{
				return null;
			}

			if ( $columns[2] !== 'image' )
			{
				continue;
			}

			$images[] = [
				'width'  => (int) $columns[3],
				'height' => (int) $columns[4],
				'xPpi'   => (int) $columns[12],
				'yPpi'   => (int) $columns[13],
			];
		}

		return $images;
	}

	/**
	 * The page's longer side in pixels at its one image's resolution, or
	 * null where the page is not a scan.
	 *
	 * @param array{float, float}                                              $page
	 * @param list<array{width: int, height: int, xPpi: int, yPpi: int}>|null $images
	 * @param float                                                            $coverage the share of the page, each way, the image must cover
	 */
	public static function box(
		array  $page,
		?array $images,
		float  $coverage = 0.9,
	): ?int
	{
		if ( $images === null || count( $images ) !== 1 )
		{
			return null;
		}

		[ $pageWidth, $pageHeight ] = $page;
		$image                      = $images[0];

		if ( $image['xPpi'] <= 0 || $image['yPpi'] <= 0 )
		{
			return null;
		}

		// Points the image covers, at its resolution; a page turned on its
		// side swaps them.
		$width  = (float) $image['width'] / (float) $image['xPpi'] * 72.0;
		$height = (float) $image['height'] / (float) $image['yPpi'] * 72.0;

		$covers = static fn(
			float $w,
			float $h,
		): bool => $w >= $pageWidth * $coverage && $h >= $pageHeight * $coverage;

		if ( ! $covers( $width, $height ) && ! $covers( $height, $width ) )
		{
			return null;
		}

		return (int) ceil(
			max(
				$pageWidth / 72.0 * (float) $image['xPpi'],
				$pageHeight / 72.0 * (float) $image['yPpi'],
			),
		);
	}
}
