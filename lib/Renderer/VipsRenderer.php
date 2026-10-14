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
 * The output is a PNG, a JPEG at quality 95 laid on preview_background, or a
 * WebP at quality 95; Nextcloud keeps the type and re-encodes at its own
 * quality. convert() writes the same from a PNG, for poppler's pages:
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
 * malloc arenas, with which a HEIC and a WebP passed at 640 MB rather than
 * 768, untrusted decoders blocked while vips_block_untrusted is on, and its
 * spill to disc in the render folder, which the provider removes. Under the
 * cap libvips or libtiff says it is out of memory, or fails to start a
 * thread and aborts; any of these is a render out of memory. libheif may
 * crash there instead, with no word: a render killed by SIGSEGV or SIGABRT
 * says the cap may be why.
 *
 * A TIFF is read a strip at a time, so one in a single large strip can need
 * far more memory than its preview: 6000 x 6000 floats in one LZW strip,
 * 151 MB, failed under 256 MB, 15000 x 15000 bytes, 365 MB, under 512. One
 * that runs out of memory is re-striped by tiffcp and tried again
 * (restripped()); tiffcp needs about the compressed strip.
 *
 * A type whose decoder `vips -l` does not list, or lists as untrusted
 * while those are blocked, is unsupported (VipsLoaders).
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
	 * The quality a JPEG or WebP preview is written at, before Nextcloud
	 * re-encodes it at its own (preview jpeg_quality, webp_quality).
	 */
	public const QUALITY = 95;

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

	/** SIGABRT and SIGSEGV: how libvips and libheif end under the cap. */
	private const CRASH_SIGNALS = [ 6, 11 ];


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
		$loaders = $this->loaders->loaders();

		if ( $decoder === null || $loaders === null )
		{
			return null;
		}

		return match ( true )
		{
			! array_key_exists( $decoder, $loaders )                                                => new Unsupported( $decoder, Unsupported::MISSING ),
			$loaders[ $decoder ] && $this->config->getScalar( ConfigLexicon::VIPS_BLOCK_UNTRUSTED ) => new Unsupported( $decoder, Unsupported::UNTRUSTED ),
			default                                                                                 => null,
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
			$environment = $this->environment( dirname( $output ) );
			$cap         = (int) $this->config->getScalar( ConfigLexicon::VIPS_PRLIMIT_AS_MB );

			// Not enlarged: a header that cannot be read leaves the box, and the
			// thumbnail says what is wrong with the file.
			$run    = $this->runner->run(
				self::HEADER,
				[ RunOptions::path( RunOptions::INPUT ) ],
				new RunOptions( true, $environment, $cap, [ RunOptions::INPUT => $input ] ),
			);
			$header = $run->isSuccess() ? self::header( $run->stderr ) : null;

			if ( $header !== null && max( $header['width'], $header['height'] ) < $box )
			{
				$box = max( $header['width'], $header['height'] );
			}

			$outcome = $this->thumbnail( RunOptions::INPUT, $input, $box, $output, $format, $environment, $cap );

			// A TIFF in one strip, or a few large ones, is read a strip at a
			// time; one that ran out of memory gets a second chance.
			if ( $outcome->outcome === Outcome::OUT_OF_MEMORY && $header !== null && $header['loader'] === 'tiffload' )
			{
				return $this->restripped( $input, $header, $box, $output, $format, $environment, $cap, $outcome );
			}

			return $outcome;
		}
		finally
		{
			fclose( $input );
		}
	}

	/**
	 * @param resource              $handle      the image, handed on as `$descriptor`
	 * @param array<string, string> $environment
	 */
	private function thumbnail(
		int           $descriptor,
		mixed         $handle,
		int           $box,
		string        $output,
		PreviewFormat $format,
		array         $environment,
		int           $cap,
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
			new RunOptions( false, $environment, $cap, [ $descriptor => $handle ] ),
		);

		return self::judged( $outcome, $output );
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
	 * decoded one: measured with libtiff 4.5, a 348 MB one-strip LZW TIFF
	 * passed under 384 MB of address space, so it runs under libvips' cap.
	 * Where the copy cannot be made, the render stays out of memory, with
	 * the reason.
	 *
	 * @param resource                                                              $input
	 * @param array{width: int, height: int, bands: int, bytes: int, loader: string} $header
	 * @param array<string, string>                                                 $environment
	 */
	private function restripped(
		mixed         $input,
		array         $header,
		int           $box,
		string        $output,
		PreviewFormat $format,
		array         $environment,
		int           $cap,
		RenderOutcome $outOfMemory,
	): RenderOutcome
	{
		$bytes   = $header['width'] * $header['height'] * $header['bands'] * $header['bytes'];
		$problem = match ( true )
		{
			! $this->binaries->locate( self::RESTRIPPER )->isFound() => 'tiffcp is not there',
			default                                                  => $this->scratch->problem( $bytes ),
		};

		if ( $problem !== null )
		{
			return $outOfMemory->as( Outcome::OUT_OF_MEMORY, $outOfMemory->reason . '; no one-strip fallback: ' . $problem );
		}

		$path = $this->scratch->path() . '/lfp-restrip-' . bin2hex( random_bytes( 8 ) ) . '.tif';
		$copy = @fopen( $path, 'x+b' );

		if ( $copy === false )
		{
			return $outOfMemory->as( Outcome::OUT_OF_MEMORY, $outOfMemory->reason . '; no one-strip fallback: ' . $path . ' could not be made' );
		}

		// Unlinked at once: the descriptor is all that is left of it.
		@unlink( $path );

		try
		{
			// `,0` is the first image only.
			$copied = $this->runner->run(
				self::RESTRIPPER,
				[ '-c', 'none', '-r', '64', RunOptions::path( RunOptions::INPUT ) . ',0', RunOptions::path( RunOptions::COPY ) ],
				new RunOptions( false, $environment, $cap, [ RunOptions::INPUT => $input, RunOptions::COPY => $copy ] ),
			);

			if ( ! $copied->isSuccess() || ( fstat( $copy )['size'] ?? 0 ) === 0 )
			{
				return $copied->as( $copied->isSuccess() ? Outcome::NO_OUTPUT : $copied->outcome, 'the one-strip fallback: tiffcp: ' . $copied->reason );
			}

			$outcome = $this->thumbnail( RunOptions::COPY, $copy, $box, $output, $format, $environment, $cap );

			return $outcome->isSuccess()
				? $outcome
				: $outcome->as( $outcome->outcome, 'the one-strip fallback: ' . $outcome->reason );
		}
		finally
		{
			fclose( $copy );
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
	 * @param string $png    an absolute path ending in `.png`
	 * @param string $output an absolute path ending in `.png`, `.jpg` or `.webp`
	 */
	public function convert(
		string $png,
		string $output,
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
			),
		);

		return self::judged( $outcome, $output );
	}

	/**
	 * What every libvips run is given: two malloc arenas, its spill in the
	 * render folder, and untrusted decoders blocked while that is on.
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
		if ( $this->config->getScalar( ConfigLexicon::VIPS_BLOCK_UNTRUSTED ) )
		{
			$environment['VIPS_BLOCK_UNTRUSTED'] = '1';
		}

		return $environment;
	}

	/**
	 * The options a preview is saved with: a lossy one at a quality that
	 * Nextcloud's own re-encoding, at its quality, hardly adds to, and a
	 * JPEG laid on the background, since it keeps no transparency.
	 */
	private function saveOptions( PreviewFormat $format ): string
	{
		return match ( $format )
		{
			PreviewFormat::PNG  => '',
			PreviewFormat::JPEG => sprintf(
				'[Q=%d,background=%d]',
				self::QUALITY,
				$this->config->getScalar( ConfigLexicon::PREVIEW_BACKGROUND ) === 'black' ? 0 : 255,
			),
			PreviewFormat::WEBP => sprintf( '[Q=%d]', self::QUALITY ),
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
	 * Out of memory, where libvips said so, or no output where it said
	 * nothing.
	 */
	private static function judged(
		RenderOutcome $outcome,
		string        $output,
	): RenderOutcome
	{
		if ( ! $outcome->isSuccess() && self::outOfMemory( $outcome->stderr ) )
		{
			return $outcome->as( Outcome::OUT_OF_MEMORY, 'out of memory under the address-space cap (vips_prlimit_as_mb)' );
		}

		// A crash is not named out of memory: a hostile file can cause one.
		if ( $outcome->outcome === Outcome::KILLED && in_array( $outcome->signal, self::CRASH_SIGNALS, true ) )
		{
			return $outcome->as( Outcome::KILLED, $outcome->reason . '; under the address-space cap, a crash is often memory running out (vips_prlimit_as_mb)' );
		}

		if ( $outcome->isSuccess() && ! is_file( $output ) )
		{
			return $outcome->as( Outcome::NO_OUTPUT, 'exit status 0, but no image written' );
		}

		return $outcome;
	}

	private static function outOfMemory( string $stderr ): bool
	{
		foreach ( self::OUT_OF_MEMORY as $message )
		{
			if ( str_contains( $stderr, $message ) )
			{
				return true;
			}
		}

		return false;
	}
}
