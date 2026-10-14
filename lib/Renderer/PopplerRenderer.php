<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Renderer;

use OCA\LargeFilePreviews\Config\SupportedTypes;
use OCA\LargeFilePreviews\Service\CommandRunner;
use OCA\LargeFilePreviews\Service\Outcome;
use OCA\LargeFilePreviews\Service\RenderOutcome;

/**
 * PDF and Illustrator (9 and later, which is PDF inside) through pdftoppm:
 *
 *     pdftoppm -f 1 -l 1 -scale-to <N> -png -singlefile <input> <outstem>
 *     pdftoppm -f 1 -l 1 -scale-to <N> -jpeg -jpegopt quality=95 -singlefile <input> <outstem>
 *
 * pdftoppm reads the cross-reference table at the end of the file and
 * follows the page tree to page 1, loading that page's objects only, so a
 * file of gigabytes renders in under a second; it needs to seek, which is
 * why it is given the path and never a pipe. `-scale-to` fits the page into
 * an N x N box, and `-singlefile` writes exactly `<outstem>.png` or `.jpg`.
 * Many real files print warnings and still exit 0: success is the exit
 * status and the file, never an empty stderr. A page that is one scan is
 * rendered at the scan's own resolution where that is smaller than the box
 * (PageProbe).
 *
 * pdftoppm writes no WebP: its PNG is converted by libvips, and without
 * libvips a WebP preview is a JPEG (format()).
 */
class PopplerRenderer
    implements
    IRenderer
{

//  constants

	public const PROGRAM = 'pdftoppm';


//  constructor

	public function __construct(
		private readonly CommandRunner $runner,
		private readonly PageProbe     $probe,
		private readonly VipsRenderer  $vips,
	)
	{
	}


//  other non-static methods

	#[\Override]
	public function program(): string
	{
		return self::PROGRAM;
	}

	#[\Override]
	public function supports( string $mime ): bool
	{
		return in_array( $mime, SupportedTypes::DOCUMENT_TYPES, true );
	}

	/**
	 * poppler decodes PDF itself.
	 */
	#[\Override]
	public function unsupported( string $mime ): ?Unsupported
	{
		return null;
	}

	/**
	 * WebP only where libvips can convert the page.
	 */
	#[\Override]
	public function format( PreviewFormat $wanted ): PreviewFormat
	{
		return $wanted === PreviewFormat::WEBP && ! $this->vips->canConvert()
			? PreviewFormat::JPEG
			: $wanted;
	}

	#[\Override]
	public function render(
		string $localPath,
		int    $box,
		string $output,
	): RenderOutcome
	{
		$format = PreviewFormat::ofPath( $output );

		// An absolute path cannot be read as an option.
		if ( ! str_starts_with( $localPath, '/' ) || ! str_starts_with( $output, '/' ) || $format === null || $box < 1 )
		{
			return new RenderOutcome( Outcome::START_FAILED, 'not an absolute input path, an absolute .png, .jpg or .webp output and a box' );
		}

		$stem = substr( $output, 0, -strlen( $format->extension() ) - 1 );

		// A scanned page at its own resolution, where that is smaller: an
		// enlarged scan is a larger preview with no more detail.
		$scan = $this->probe->scanBox( $localPath );

		if ( $scan !== null && $scan > 0 && $scan < $box )
		{
			$box = $scan;
		}

		// pdftoppm's own file: the preview, or the page to convert.
		$written = $format === PreviewFormat::JPEG ? $output : $stem . '.png';

		$outcome = $this->runner->run(
			self::PROGRAM,
			[
				'-f',
				'1',
				'-l',
				'1',
				'-scale-to',
				(string) $box,
				...( $format === PreviewFormat::JPEG
					? [ '-jpeg', '-jpegopt', sprintf( 'quality=%d', VipsRenderer::QUALITY ) ]
					: [ '-png' ] ),
				'-singlefile',
				$localPath,
				$stem,
			],
		);

		// Under the address-space cap, poppler says so on stderr and still
		// exits 0, leaving a PNG of a few bytes: measured with poppler 24.02.
		if ( $outcome->isSuccess() && str_contains( $outcome->stderr, 'Out of memory' ) )
		{
			return $outcome->as( Outcome::OUT_OF_MEMORY, 'out of memory under the address-space cap (prlimit_as_mb)' );
		}

		if ( $outcome->isSuccess() && ! is_file( $written ) )
		{
			return $outcome->as( Outcome::NO_OUTPUT, 'exit status 0, but no image written' );
		}

		if ( ! $outcome->isSuccess() || $written === $output )
		{
			return $outcome;
		}

		return $this->vips->convert( $written, $output );
	}
}
