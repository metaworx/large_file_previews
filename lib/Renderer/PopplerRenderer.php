<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Renderer;

use OCA\LargeFilePreviews\Config\ConfigLexicon;
use OCA\LargeFilePreviews\Config\SupportedTypes;
use OCA\LargeFilePreviews\Service\CommandRunner;
use OCA\LargeFilePreviews\Service\ConfigurationService;
use OCA\LargeFilePreviews\Service\Deadline;
use OCA\LargeFilePreviews\Service\Outcome;
use OCA\LargeFilePreviews\Service\RenderOutcome;
use OCA\LargeFilePreviews\Service\RunOptions;

/**
 * PDF and Illustrator (9 and later, which is PDF inside) through pdftoppm:
 *
 *     pdftoppm -f 1 -l 1 -scale-to <N> -png -singlefile /dev/fd/4 <outstem>
 *     pdftoppm -f 1 -l 1 -scale-to <N> -jpeg -jpegopt quality=<Q>,optimize=y -singlefile /dev/fd/4 <outstem>
 *
 * The file is opened here and handed on as descriptor 4 (RunOptions), so
 * its name never reaches pdftoppm. pdftoppm reads the cross-reference table
 * at the end of the file and follows the page tree to page 1, loading that
 * page's objects only, so a file of gigabytes renders in under a second; it
 * needs to seek, which `/dev/fd/4`, the file itself reopened, allows and a
 * pipe would not. `-scale-to` fits the page into
 * an N x N box, and `-singlefile` writes exactly `<outstem>.png` or `.jpg`.
 * Many real files print warnings and still exit 0: success is the exit
 * status and the file, never an empty stderr. A page that is one scan is
 * rendered at the scan's own resolution where that is smaller than the box
 * (PageProbe). A JPEG is at preview_quality, its Huffman tables optimized:
 * lossless, and a third of the size of pdftoppm's default on a drawn page.
 *
 * pdftoppm writes no WebP: its PNG is converted by libvips, and without
 * libvips a WebP preview is a JPEG (format()).
 *
 * The run is judged as libvips' are (RunJudge), and the probe, pdftoppm and
 * a conversion share one timeout_seconds (Deadline).
 */
class PopplerRenderer
    implements
    IRenderer
{

//  constants

	public const PROGRAM = 'pdftoppm';

	/**
	 * What poppler prints when the cap stops it, whatever its exit status:
	 * measured with poppler 24.02, which exits 0 and leaves a PNG of a few
	 * bytes.
	 */
	private const OUT_OF_MEMORY = [ 'Out of memory' ];


//  constructor

	public function __construct(
		private readonly CommandRunner        $runner,
		private readonly PageProbe            $probe,
		private readonly VipsRenderer         $vips,
		private readonly ConfigurationService $config,
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

		$stem  = substr( $output, 0, -strlen( $format->extension() ) - 1 );
		$input = @fopen( $localPath, 'rb' );

		if ( $input === false )
		{
			return new RenderOutcome( Outcome::START_FAILED, 'the file could not be opened' );
		}

		// The probe, pdftoppm and a conversion share the render's time.
		$deadline = Deadline::after( (int) $this->config->getScalar( ConfigLexicon::TIMEOUT_SECONDS ) );

		try
		{
			// A scanned page at its own resolution, where that is smaller: an
			// enlarged scan is a larger preview with no more detail.
			$scan = $this->probe->scanBox( $input, $deadline );

			if ( $scan !== null && $scan > 0 && $scan < $box )
			{
				$box = $scan;
			}

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
						? [ '-jpeg', '-jpegopt', sprintf( 'quality=%d,optimize=y', $this->config->previewQuality() ) ]
						: [ '-png' ] ),
					'-singlefile',
					RunOptions::path( RunOptions::INPUT ),
					$stem,
				],
				new RunOptions( descriptors: [ RunOptions::INPUT => $input ], deadline: $deadline, writable: dirname( $stem ) ),
			);
		}
		finally
		{
			fclose( $input );
		}

		// pdftoppm's own file: the preview, or the page to convert.
		$written = $format === PreviewFormat::JPEG ? $output : $stem . '.png';
		$outcome = RunJudge::judged( $outcome, $written, self::OUT_OF_MEMORY, ConfigLexicon::PRLIMIT_AS_MB );

		if ( ! $outcome->isSuccess() || $written === $output )
		{
			return $outcome;
		}

		return $this->vips->convert( $written, $output, $deadline );
	}
}
