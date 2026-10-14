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
use OCA\LargeFilePreviews\Service\Outcome;
use OCA\LargeFilePreviews\Service\RenderOutcome;

/**
 * Images through libvips:
 *
 *     vipsheader <input>
 *     vipsthumbnail <input> --size <N> --vips-concurrency=1 -o <output>
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
 * decoders blocked, and its spill to disc in the render folder, which the
 * provider removes. Under the cap libvips says "out of memory", fails to
 * start a thread or aborts; any of these is a render out of memory.
 */
class VipsRenderer
    implements
    IRenderer
{

//  constants

	public const PROGRAM = 'vipsthumbnail';

	public const HEADER = 'vipsheader';

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

	#[\Override]
	public function render(
		string $localPath,
		int    $box,
		string $outputPng,
	): RenderOutcome
	{
		// An absolute path cannot be read as an option.
		if ( ! str_starts_with( $localPath, '/' ) || ! str_starts_with( $outputPng, '/' ) || ! str_ends_with( $outputPng, '.png' ) || $box < 1 )
		{
			return new RenderOutcome( Outcome::START_FAILED, 'not an absolute input path, an absolute .png output and a box' );
		}

		if ( str_ends_with( $localPath, ']' ) )
		{
			return new RenderOutcome( Outcome::START_FAILED, 'libvips cannot open a file whose name ends in "]"' );
		}

		if ( str_contains( $outputPng, '%' ) )
		{
			return new RenderOutcome( Outcome::START_FAILED, 'an output path holding "%" is a format to vipsthumbnail' );
		}

		$environment = [
			'VIPS_BLOCK_UNTRUSTED' => '1',
			'MALLOC_ARENA_MAX'     => '2',
			'TMPDIR'               => dirname( $outputPng ),
		];
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
				'--vips-concurrency=1',
				'-o',
				$outputPng,
			],
			false,
			$environment,
			$cap,
		);

		if ( ! $outcome->isSuccess() && self::outOfMemory( $outcome->stderr ) )
		{
			return $outcome->as( Outcome::OUT_OF_MEMORY, 'out of memory under the address-space cap (vips_prlimit_as_mb)' );
		}

		if ( $outcome->isSuccess() && ! is_file( $outputPng ) )
		{
			return $outcome->as( Outcome::NO_OUTPUT, 'exit status 0, but no PNG written' );
		}

		return $outcome;
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
