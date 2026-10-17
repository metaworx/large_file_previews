<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Renderer;

use OCA\LargeFilePreviews\Config\ConfigLexicon;
use OCA\LargeFilePreviews\Config\SupportedTypes;
use OCA\LargeFilePreviews\Service\BinaryLocator;
use OCA\LargeFilePreviews\Service\CommandRunner;
use OCA\LargeFilePreviews\Service\ConfigurationService;
use OCA\LargeFilePreviews\Service\Deadline;
use OCA\LargeFilePreviews\Service\Outcome;
use OCA\LargeFilePreviews\Service\RenderOutcome;
use OCA\LargeFilePreviews\Service\RunOptions;
use OCA\LargeFilePreviews\Service\ScratchDirectory;

/**
 * Images through libvips:
 *
 *     vipsheader /dev/fd/4
 *     vipsthumbnail /dev/fd/4 --size <N> --vips-concurrency=<C> -o <output>[<save options>]
 *
 * The file is opened here and handed on as descriptor 4 (RunOptions): its
 * name never reaches libvips, nor what libvips prints, so no name can be
 * read as a header line or as "out of memory", and one ending in `]` is not
 * read as load options.
 *
 * The output is a PNG, a JPEG at preview_quality laid on preview_background,
 * or a WebP at preview_quality; Nextcloud keeps the type, and the render as
 * it is where the provider streams it. convert() writes the same from a PNG,
 * for poppler's pages:
 *
 *     vips copy <page.png> <output>[<save options>]
 *
 * vipsthumbnail decodes only what the thumbnail needs: a pyramid TIFF from
 * its nearest level, a JPEG shrunk while it is decoded, anything else in
 * strips. The header is read first so that N is never larger than the
 * image, which is not enlarged; `--size N>` would say the same, but picks
 * a pyramid TIFF's smallest level. No `[page=0]`: JPEG and PNG have no such
 * option, and page 0 is the default.
 *
 * The output is a format string, its `%s` the input's name, and `%%` not
 * unescaped, so an output path holding `%` is not run; the provider's never
 * does.
 *
 * Each run has libvips' own address-space cap (vips_prlimit_as_mb), two
 * malloc arenas, with which a HEIC and a WebP passed at 640 MiB rather than
 * 768, untrusted decoders blocked unless vips_allow_untrusted is set, and its
 * spill to disc in the render folder, which the provider removes. Under the
 * cap libvips or libtiff says it is out of memory, or fails to start a
 * thread and aborts; any of these is a render out of memory. libheif may
 * crash there instead, with no word: a render killed by SIGSEGV or SIGABRT
 * says the cap may be why.
 *
 * A TIFF is read a strip at a time, so one in a single large strip can need
 * far more memory than its preview: 6000 x 6000 floats in one LZW strip,
 * 151 MB, failed under 256 MiB, 15000 x 15000 bytes, 348 MiB, under 512.
 * One that runs out of memory, and whose header was read, is re-striped by
 * tiffcp and tried again (restripped()); tiffcp needs about the compressed
 * strip.
 *
 * Every run is judged by RunJudge, and the header, the thumbnail and a
 * re-striped copy share one timeout_seconds (Deadline).
 *
 * A type whose decoder `vips -l` does not list, or lists as untrusted
 * while those are blocked, is unsupported (VipsLoaders); and every type,
 * while they are, where libvips is older than 8.13 and cannot block them.
 */
class VipsRenderer
    implements
    IRenderer
{

//  constants

	public const PROGRAM = 'vipsthumbnail';

	public const HEADER = 'vipsheader';

	public const CONVERTER = 'vips';

	public const RESTRIPPER = 'tiffcp';

	/**
	 * What the copy may take beyond its pixels: its directory and the
	 * offsets of its strips, a few bytes for each 64 rows.
	 */
	private const COPY_MARGIN_BYTES = 16_777_216;

	/**
	 * Bytes a sample of each libvips band format takes; an unknown one is
	 * counted large.
	 */
	private const SAMPLE_BYTES
		 = [
			'uchar'     => 1,
			'char'      => 1,
			'ushort'    => 2,
			'short'     => 2,
			'uint'      => 4,
			'int'       => 4,
			'float'     => 4,
			'complex'   => 8,
			'double'    => 8,
			'dpcomplex' => 16,
		];

	/**
	 * What libvips, glib and libtiff print when the cap stops them,
	 * measured with libvips 8.15 and libtiff 4.5.
	 */
	private const OUT_OF_MEMORY
		 = [
			'out of memory',
			'failed to allocate memory',
			'Error creating thread',
			'No space for data buffer',
		];


//  constructor

	public function __construct(
		private readonly CommandRunner        $runner,
		private readonly ConfigurationService $config,
		private readonly VipsLoaders          $loaders,
		private readonly BinaryLocator        $binaries,
		private readonly ScratchDirectory     $scratch,
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
		return in_array( $mime, SupportedTypes::IMAGE_TYPES, true );
	}

	/**
	 * Unknown where `vips -l` cannot be asked: then the render says.
	 */
	#[\Override]
	public function unsupported( string $mime ): ?Unsupported
	{
		$decoder = SupportedTypes::DECODERS[ $mime ] ?? null;

		if ( $decoder === null )
		{
			return null;
		}

		$allowed = $this->config->untrustedAllowed();

		// Before 8.13 libvips blocks nothing, and picks its decoder by the
		// content: a file of any image type could reach an untrusted one.
		if ( ! $allowed && $this->loaders->canBlockUntrusted() === false )
		{
			return new Unsupported( $decoder, Unsupported::UNBLOCKABLE );
		}

		$loaders = $this->loaders->loaders();

		if ( $loaders === null )
		{
			return null;
		}

		return match ( true )
		{
			! array_key_exists( $decoder, $loaders ) => new Unsupported( $decoder, Unsupported::MISSING ),
			$loaders[ $decoder ] && ! $allowed       => new Unsupported( $decoder, Unsupported::UNTRUSTED ),
			default                                  => null,
		};
	}

	/**
	 * libvips writes each of them.
	 */
	#[\Override]
	public function format( PreviewFormat $wanted ): PreviewFormat
	{
		return $wanted;
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

		if ( str_contains( $output, '%' ) )
		{
			return new RenderOutcome( Outcome::START_FAILED, 'an output path holding "%" is a format to vipsthumbnail' );
		}

		$input = @fopen( $localPath, 'rb' );

		if ( $input === false )
		{
			return new RenderOutcome( Outcome::START_FAILED, 'the file could not be opened' );
		}

		try
		{
			$run = new RunOptions(
				environment: $this->environment( dirname( $output ) ),
				prlimitAsMb: (int) $this->config->getScalar( ConfigLexicon::VIPS_PRLIMIT_AS_MB ),
				deadline: $this->deadline(),
			);

			// Not enlarged: a header that cannot be read leaves the box, and the
			// thumbnail says what is wrong with the file.
			$read   = $this->runner->run(
				self::HEADER,
				[ RunOptions::path( RunOptions::INPUT ) ],
				self::with( $run, [ RunOptions::INPUT => $input ], keepStdout: true ),
			);
			$header = $read->isSuccess() ? self::header( $read->stderr ) : null;

			if ( $header !== null && max( $header['width'], $header['height'] ) < $box )
			{
				$box = max( $header['width'], $header['height'] );
			}

			$outcome = $this->thumbnail( RunOptions::INPUT, $input, $box, $output, $format, $run );

			// A TIFF in one strip, or a few large ones, is read a strip at a
			// time; one that ran out of memory gets a second chance.
			if ( $outcome->outcome === Outcome::OUT_OF_MEMORY && $header !== null && $header['loader'] === 'tiffload' )
			{
				return $this->restripped( $input, $header, $box, $output, $format, $run, $outcome );
			}

			return $outcome;
		}
		finally
		{
			fclose( $input );
		}
	}

	/**
	 * @param resource   $handle the image, handed on as `$descriptor`
	 * @param RunOptions $run    the render's environment, cap and deadline
	 */
	private function thumbnail(
		int           $descriptor,
		mixed         $handle,
		int           $box,
		string        $output,
		PreviewFormat $format,
		RunOptions    $run,
	): RenderOutcome
	{
		$outcome = $this->runner->run(
			self::PROGRAM,
			[
				RunOptions::path( $descriptor ),
				'--size',
				(string) $box,
				sprintf( '--vips-concurrency=%d', (int) $this->config->getScalar( ConfigLexicon::VIPS_CONCURRENCY ) ),
				'-o',
				$output . $this->saveOptions( $format ),
			],
			self::with( $run, [ $descriptor => $handle ] ),
		);

		return RunJudge::judged( $outcome, $output, self::OUT_OF_MEMORY, ConfigLexicon::VIPS_PRLIMIT_AS_MB );
	}

	/**
	 * The one-strip fallback: the TIFF's first image re-striped by tiffcp
	 * into an uncompressed copy of 64 rows a strip, which libvips then reads
	 * a few rows at a time.
	 *
	 *     tiffcp -c none -r 64 /dev/fd/4,0 /dev/fd/3
	 *
	 * The copy is a file in the scratch directory, unlinked as soon as it is
	 * opened, and handed to tiffcp and then to vipsthumbnail as descriptor 3:
	 * its space goes with the last descriptor, whatever ends the worker, and
	 * no copy can be left behind. tiffcp holds the compressed strip, not the
	 * decoded one: measured with libtiff 4.5, a 348 MiB one-strip LZW TIFF
	 * passed under 384 MiB of address space, so it runs under libvips' cap.
	 *
	 * The disc is guarded three ways: a copy larger than tiff_restrip_max_mb
	 * is not made; one copy is made at a time, under a lock in the scratch
	 * directory, so the free-space check holds; and tiffcp may not write
	 * past the copy's size (`prlimit --fsize`), whatever the header said.
	 * Where the copy cannot be made, the render stays out of memory, with
	 * the reason; where the fallback fails, its outcome is the render's, and
	 * its reason follows the first one. The lock is waited for, and the copy
	 * made, within the render's own time.
	 *
	 * @param resource                                                              $input
	 * @param array{width: int, height: int, bands: int, bytes: int, loader: string} $header
	 */
	private function restripped(
		mixed         $input,
		array         $header,
		int           $box,
		string        $output,
		PreviewFormat $format,
		RunOptions    $run,
		RenderOutcome $outOfMemory,
	): RenderOutcome
	{
		$bytes   = $header['width'] * $header['height'] * $header['bands'] * $header['bytes'];
		$maximum = (int) $this->config->getScalar( ConfigLexicon::TIFF_RESTRIP_MAX_MB ) * 1_048_576;
		$without = static fn( string $why ): RenderOutcome => $outOfMemory->as( Outcome::OUT_OF_MEMORY, $outOfMemory->reason . '; no one-strip fallback: ' . $why );
		$failed  = static fn( RenderOutcome $fallback ): RenderOutcome => $fallback->as( $fallback->outcome, $outOfMemory->reason . '; the one-strip fallback: ' . $fallback->reason );

		if ( ! $this->binaries->locate( self::RESTRIPPER )->isFound() )
		{
			return $without( 'tiffcp is not there' );
		}

		if ( $bytes > $maximum )
		{
			return $without( sprintf( 'the copy would take %d MB, more than tiff_restrip_max_mb', (int) ceil( $bytes / 1_048_576 ) ) );
		}

		// One copy at a time, so that two cannot each pass the free-space
		// check and together fill the disc.
		$lock = $this->scratch->lock( $run->deadline?->left() ?? 0 );

		if ( $lock === null )
		{
			return $without( 'another one-strip copy is being made' );
		}

		$copy = null;

		try
		{
			$problem = $this->scratch->problem( $bytes );

			if ( $problem !== null )
			{
				return $without( $problem );
			}

			$path = $this->scratch->path() . '/lfp-restrip-' . bin2hex( random_bytes( 8 ) ) . '.tif';
			$copy = @fopen( $path, 'x+b' );

			if ( $copy === false )
			{
				$copy = null;

				return $without( $path . ' could not be made' );
			}

			// Unlinked at once: the descriptor is all that is left of it.
			@unlink( $path );

			// `,0` is the first image only. The copy may not grow past its
			// size and the TIFF's own structures, whatever the header said.
			$copied = RunJudge::judged(
				$this->runner->run(
					self::RESTRIPPER,
					[ '-c', 'none', '-r', '64', RunOptions::path( RunOptions::INPUT ) . ',0', RunOptions::path( RunOptions::COPY ) ],
					new RunOptions(
						environment: $run->environment,
						prlimitAsMb: $run->prlimitAsMb,
						descriptors: [ RunOptions::INPUT => $input, RunOptions::COPY => $copy ],
						fsizeBytes: $bytes + self::COPY_MARGIN_BYTES,
						deadline: $run->deadline,
					),
				),
				null,
				self::OUT_OF_MEMORY,
				ConfigLexicon::VIPS_PRLIMIT_AS_MB,
				'the copy\'s size, from the header',
			);

			if ( $copied->isSuccess() && ( fstat( $copy )['size'] ?? 0 ) === 0 )
			{
				$copied = $copied->as( Outcome::NO_OUTPUT, 'exit status 0, but no copy written' );
			}

			if ( ! $copied->isSuccess() )
			{
				return $failed( $copied->as( $copied->outcome, 'tiffcp: ' . $copied->reason ) );
			}

			$outcome = $this->thumbnail( RunOptions::COPY, $copy, $box, $output, $format, $run );

			return $outcome->isSuccess()
				? $outcome
				: $failed( $outcome );
		}
		finally
		{
			if ( $copy !== null )
			{
				fclose( $copy );
			}

			fclose( $lock );
		}
	}

	/**
	 * Whether a PNG can be converted here: `vips` is there.
	 */
	public function canConvert(): bool
	{
		return $this->binaries->locate( self::CONVERTER )->isFound();
	}

	/**
	 * Converts a PNG into the format `$output`'s extension names, under the
	 * same cap and environment as a render: a page poppler wrote, where a
	 * WebP preview is asked for.
	 *
	 * @param string        $png      an absolute path ending in `.png`
	 * @param string        $output   an absolute path ending in `.png`, `.jpg` or `.webp`
	 * @param Deadline|null $deadline the render's, of which this is a part
	 */
	public function convert(
		string    $png,
		string    $output,
		?Deadline $deadline = null,
	): RenderOutcome
	{
		$format = PreviewFormat::ofPath( $output );

		if ( ! str_starts_with( $png, '/' ) || ! str_ends_with( $png, '.png' ) || ! str_starts_with( $output, '/' ) || $format === null )
		{
			return new RenderOutcome( Outcome::START_FAILED, 'not an absolute .png input and an absolute .png, .jpg or .webp output' );
		}

		$outcome = $this->runner->run(
			self::CONVERTER,
			[ 'copy', $png, $output . $this->saveOptions( $format ) ],
			new RunOptions(
				environment: $this->environment( dirname( $output ) ),
				prlimitAsMb: (int) $this->config->getScalar( ConfigLexicon::VIPS_PRLIMIT_AS_MB ),
				deadline: $deadline ?? $this->deadline(),
			),
		);

		return RunJudge::judged( $outcome, $output, self::OUT_OF_MEMORY, ConfigLexicon::VIPS_PRLIMIT_AS_MB );
	}

	/**
	 * When a render starting now must be done.
	 */
	private function deadline(): Deadline
	{
		return Deadline::after( (int) $this->config->getScalar( ConfigLexicon::TIMEOUT_SECONDS ) );
	}

	/**
	 * What every libvips run is given: two malloc arenas, its spill in the
	 * render folder, and untrusted decoders blocked unless system config
	 * allows them.
	 *
	 * @return array<string, string>
	 */
	private function environment( string $folder ): array
	{
		$environment = [
			'MALLOC_ARENA_MAX' => '2',
			'TMPDIR'           => $folder,
		];

		// libvips blocks where the variable is set at all, whatever its value.
		if ( ! $this->config->untrustedAllowed() )
		{
			$environment['VIPS_BLOCK_UNTRUSTED'] = '1';
		}

		return $environment;
	}

	/**
	 * The options a preview is saved with: a lossy one at preview_quality,
	 * and a JPEG laid on the background, since it keeps no transparency,
	 * its Huffman tables optimized, which loses nothing and halves it.
	 */
	private function saveOptions( PreviewFormat $format ): string
	{
		return match ( $format )
		{
			PreviewFormat::PNG  => '',
			PreviewFormat::JPEG => sprintf(
				'[Q=%d,optimize_coding,background=%d]',
				$this->config->previewQuality(),
				$this->config->getScalar( ConfigLexicon::PREVIEW_BACKGROUND ) === 'black' ? 0 : 255,
			),
			PreviewFormat::WEBP => sprintf( '[Q=%d]', $this->config->previewQuality() ),
		};
	}


//  static methods

	/**
	 * The image's size, bands, bytes a sample and loader, from what
	 * vipsheader prints:
	 *
	 *     /path/name.tif: 22040x12059 uchar, 3 bands, srgb, tiffload
	 *
	 * The last such field on a line, since the path may hold anything.
	 *
	 * @return array{width: int, height: int, bands: int, bytes: int, loader: string}|null
	 */
	public static function header( string $header ): ?array
	{
		// The interpretation may hold a hyphen: `b-w`.
		if ( preg_match( '/^.*: (\d+)x(\d+) (\w+), (\d+) bands?, [^,\n]+, (\w+)\s*$/m', $header, $matches ) !== 1 )
		{
			return null;
		}

		$width  = (int) $matches[1];
		$height = (int) $matches[2];

		if ( $width < 1 || $height < 1 )
		{
			return null;
		}

		return [
			'width'  => $width,
			'height' => $height,
			'bands'  => max( 1, (int) $matches[4] ),
			'bytes'  => self::SAMPLE_BYTES[ $matches[3] ] ?? 8,
			'loader' => $matches[5],
		];
	}

	/**
	 * The render's options with the descriptors of one run.
	 *
	 * @param array<int, resource> $descriptors
	 */
	private static function with(
		RunOptions $run,
		array      $descriptors,
		bool       $keepStdout = false,
	): RunOptions
	{
		return new RunOptions( $keepStdout, $run->environment, $run->prlimitAsMb, $descriptors, $run->fsizeBytes, $run->deadline );
	}
}
