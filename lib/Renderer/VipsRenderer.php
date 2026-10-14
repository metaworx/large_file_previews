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

/**
 * Images through libvips:
 *
 *     vipsheader <input>
 *     vipsthumbnail <input> --size <N> --vips-concurrency=<C> -o <output>[<save options>]
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
 * libvips reads a trailing `[...]` of a file name as load options, and
 * cannot open a file whose name ends in `]`, options appended or not
 * (libvips 8.15); such a file is not run. The output is a format string,
 * its `%s` the input's name, and `%%` not unescaped, so an output path
 * holding `%` is not run either; the provider's never does.
 *
 * Each run has libvips' own address-space cap (vips_prlimit_as_mb), two
 * malloc arenas, which a HEIC needed to pass under 512 MB, untrusted
 * decoders blocked while vips_block_untrusted is on, and its spill to disc
 * in the render folder, which the provider removes. Under the cap libvips
 * says "out of memory", fails to start a thread or aborts; any of these is
 * a render out of memory.
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

	/**
	 * The quality a JPEG or WebP preview is written at, before Nextcloud
	 * re-encodes it at its own (preview jpeg_quality, webp_quality).
	 */
	public const QUALITY = 95;

	/**
	 * What libvips and glib print when the cap stops them, measured with
	 * libvips 8.15.
	 */
	private const OUT_OF_MEMORY
		 = [
			'out of memory',
			'failed to allocate memory',
			'Error creating thread',
		];


//  constructor

	public function __construct(
		private readonly CommandRunner        $runner,
		private readonly ConfigurationService $config,
		private readonly VipsLoaders          $loaders,
		private readonly BinaryLocator        $binaries,
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

		if ( str_ends_with( $localPath, ']' ) )
		{
			return new RenderOutcome( Outcome::START_FAILED, 'libvips cannot open a file whose name ends in "]"' );
		}

		if ( str_contains( $output, '%' ) )
		{
			return new RenderOutcome( Outcome::START_FAILED, 'an output path holding "%" is a format to vipsthumbnail' );
		}

		$environment = $this->environment( dirname( $output ) );
		$cap         = (int) $this->config->getScalar( ConfigLexicon::VIPS_PRLIMIT_AS_MB );

		// Not enlarged: a header that cannot be read leaves the box, and the
		// thumbnail says what is wrong with the file.
		$header = $this->runner->run( self::HEADER, [ $localPath ], true, $environment, $cap );
		$size   = $header->isSuccess() ? self::longerSide( $header->stderr ) : null;

		if ( $size !== null && $size < $box )
		{
			$box = $size;
		}

		$outcome = $this->runner->run(
			self::PROGRAM,
			[
				$localPath,
				'--size',
				(string) $box,
				sprintf( '--vips-concurrency=%d', (int) $this->config->getScalar( ConfigLexicon::VIPS_CONCURRENCY ) ),
				'-o',
				$output . $this->saveOptions( $format ),
			],
			false,
			$environment,
			$cap,
		);

		return self::judged( $outcome, $output );
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
			false,
			$this->environment( dirname( $output ) ),
			(int) $this->config->getScalar( ConfigLexicon::VIPS_PRLIMIT_AS_MB ),
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
	 * The longer side of the image, from what vipsheader prints:
	 *
	 *     /path/name.tif: 22040x12059 uchar, 3 bands, srgb, tiffload
	 *
	 * The last such field on a line, since the path may hold anything.
	 */
	public static function longerSide( string $header ): ?int
	{
		if ( preg_match( '/^.*: (\d+)x(\d+) \w+, \d+ bands?, /m', $header, $matches ) !== 1 )
		{
			return null;
		}

		$side = max( (int) $matches[1], (int) $matches[2] );

		return $side > 0 ? $side : null;
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
