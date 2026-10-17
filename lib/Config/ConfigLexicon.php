<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Config;

use OCP\Config\Lexicon\Entry;
use OCP\Config\Lexicon\ILexicon;
use OCP\Config\Lexicon\Strictness;
use OCP\Config\ValueType;
use OCP\IAppConfig;

/**
 * Every app-config key the app owns, with its type, default and range.
 *
 * Read and written through ConfigurationService only, which holds values to
 * the ranges below and validates what is written; a raw
 * `occ config:app:set` bypasses it, so the service also checks what it
 * reads. No key names a program's path: a path is code execution as the web
 * server's user, and app config can be written over the provisioning API by
 * an administrator without shell access. A path override lives in system
 * config (BinaryLocator).
 */
class ConfigLexicon
    implements
    ILexicon
{

//  constants

	public const MIN_SIZE_MB = 'min_size_mb';

	public const MAX_SIZE_MB = 'max_size_mb';

	public const MIN_SIZE_ALL_TYPES = 'min_size_all_types';

	public const MIMETYPES = 'mimetypes';

	public const EXTENSIONS = 'extensions';

	public const TIMEOUT_ENABLED = 'timeout_enabled';

	public const TIMEOUT_SECONDS = 'timeout_seconds';

	public const TIMEOUT_KILL_AFTER_SECONDS = 'timeout_kill_after_seconds';

	public const FAILURE_RETRY_MINUTES = 'failure_retry_minutes';

	public const PRLIMIT_ENABLED = 'prlimit_enabled';

	public const PRLIMIT_AS_MB = 'prlimit_as_mb';

	public const VIPS_PRLIMIT_AS_MB = 'vips_prlimit_as_mb';

	public const PRLIMIT_FSIZE_MB = 'prlimit_fsize_mb';

	public const TIFF_RESTRIP_MAX_MB = 'tiff_restrip_max_mb';

	public const CHOOM_ENABLED = 'choom_enabled';

	public const CHOOM_ADJUST = 'choom_adjust';

	public const SCAN_NATIVE_ENABLED = 'scan_native_enabled';

	public const SCAN_COVERAGE_PERCENT = 'scan_coverage_percent';

	public const VIPS_CONCURRENCY = 'vips_concurrency';

	public const PREVIEW_FORMAT = 'preview_format';

	public const PREVIEW_BACKGROUND = 'preview_background';

	public const PREVIEW_QUALITY = 'preview_quality';

	public const PREVIEW_STREAM = 'preview_stream';

	public const LAST_FAILURE = 'last_failure';

	/**
	 * Images start at `preview_max_filesize_image`, 50 MB unless the server
	 * says otherwise: Nextcloud's image providers refuse what is larger, so
	 * the app takes exactly what they leave. Where that has no limit, the
	 * app's start stays at Nextcloud's default.
	 */
	public const IMAGE_DEFAULT_MIN_SIZE_MB = 50;

	public const IMAGE_LIMIT_SYSTEM_KEY = 'preview_max_filesize_image';

	/**
	 * Whether libvips may use the decoders `vips -l` marks untrusted, with
	 * libvips 8.15 JPEG 2000, JPEG XL, PNM, and ImageMagick, which BMP
	 * needs. In system config, false unless
	 * set, since an administrator can write app config without shell access
	 * (ConfigurationService::untrustedAllowed()).
	 */
	public const ALLOW_UNTRUSTED_SYSTEM_KEY = 'large_file_previews.vips_allow_untrusted';

	/**
	 * Keys the app once declared, removed from app config on install and
	 * upgrade (RemoveObsoleteKeys); a value left there is never read.
	 *
	 * @var list<string>
	 */
	public const OBSOLETE
		 = [
			// The untrusted-decoder switch, now in system config.
			'vips_block_untrusted',
		];

	/**
	 * The settings that are one value each: default, for a number its
	 * range, `null` where it has no upper bound, and for a word the words
	 * it may be.
	 *
	 * @var array<string, array{default: int|bool|string, min?: int, max?: int|null, choices?: list<string>, definition: string}>
	 */
	public const SCALARS
		 = [
			self::MIN_SIZE_MB                => [
				'default'    => 0,
				'min'        => 0,
				'max'        => null,
				'definition' => 'Smallest file, in MB, the app takes where neither its MIME type nor its extension says otherwise.',
			],
			self::MAX_SIZE_MB                => [
				'default'    => -1,
				'min'        => -1,
				'max'        => null,
				'definition' => 'Largest file, in MB, the app takes where neither its MIME type nor its extension says otherwise; -1 means no limit.',
			],
			// Off, an image type ships a minimum of its own, where Nextcloud's
			// image providers stop; on, every type follows min_size_mb.
			self::MIN_SIZE_ALL_TYPES         => [
				'default'    => false,
				'definition' => 'Whether min_size_mb applies to images too; off, an image type starts at preview_max_filesize_image, where Nextcloud\'s own image providers stop.',
			],
			self::TIMEOUT_ENABLED            => [
				'default'    => true,
				'definition' => 'Whether each render runs under timeout(1).',
			],
			self::TIMEOUT_SECONDS            => [
				'default'    => 30,
				'min'        => 1,
				'max'        => 600,
				'definition' => 'Seconds a render may take, every program it runs together, before timeout(1) stops it.',
			],
			self::TIMEOUT_KILL_AFTER_SECONDS => [
				'default'    => 5,
				'min'        => 1,
				'max'        => 60,
				'definition' => 'Seconds timeout(1) waits after SIGTERM before SIGKILL.',
			],
			// A file that failed is asked for again on every page that shows
			// it; an hour spares the server the same failure, and a change to
			// the file or the settings retries it at once.
			self::FAILURE_RETRY_MINUTES      => [
				'default'    => 60,
				'min'        => 0,
				'max'        => 10080,
				'definition' => 'Minutes a file that failed to render is left to Nextcloud\'s other providers before it is tried again; 0 tries it every time. Kept in the memory cache, by the file\'s id and version.',
			],
			self::PRLIMIT_ENABLED            => [
				'default'    => true,
				'definition' => 'Whether each render runs under prlimit --as, capping its address space.',
			],
			// A 1.7 GB, 1817-page PDF rendered at 4096 pixels within 96 MB of
			// address space; 512 leaves room for heavier pages and still stops
			// a runaway early.
			self::PRLIMIT_AS_MB              => [
				'default'    => 512,
				'min'        => 256,
				'max'        => 65536,
				'definition' => 'The address-space cap of a render, in MB: not resident memory, it counts mapped libraries too.',
			],
			// libvips at its heaviest measured case, a one-strip LZW TIFF of
			// 15000 x 15000 pixels, failed at 512 and passed at 768; 1024
			// leaves room above that and still stops a runaway early.
			self::VIPS_PRLIMIT_AS_MB         => [
				'default'    => 1024,
				'min'        => 256,
				'max'        => 65536,
				'definition' => 'The address-space cap, in MB, of a render by libvips, which decodes whole strips and runs threads; prlimit_enabled switches it too.',
			],
			// A preview, its stderr and libvips' spill stay far below this;
			// a hostile file printing warnings without end does not.
			self::PRLIMIT_FSIZE_MB           => [
				'default'    => 1024,
				'min'        => 64,
				'max'        => 65536,
				'definition' => 'The largest file, in MB, a render may write: its preview, its messages, any spill; prlimit_enabled switches it too. tiffcp, making the one-strip copy, may write the copy\'s size and a margin instead, and no copy is larger than tiff_restrip_max_mb.',
			],
			self::TIFF_RESTRIP_MAX_MB        => [
				'default'    => 4096,
				'min'        => 256,
				'max'        => 1048576,
				'definition' => 'The largest uncompressed copy, in MB, the one-strip fallback makes of a TIFF; a TIFF that would need more gets no second chance.',
			],
			self::CHOOM_ENABLED            => [
				'default'    => true,
				'definition' => 'Whether each render runs under choom, so the kernel kills it before a web server worker when memory runs out.',
			],
			self::CHOOM_ADJUST               => [
				'default'    => 1000,
				'min'        => 0,
				'max'        => 1000,
				'definition' => 'The OOM score adjustment of a render; an unprivileged process may raise it only.',
			],
			self::SCAN_NATIVE_ENABLED        => [
				'default'    => true,
				'definition' => 'Whether a page that is one scan is rendered at the scan\'s own resolution where that is smaller than the preview, rather than enlarged.',
			],
			self::SCAN_COVERAGE_PERCENT      => [
				'default'    => 90,
				'min'        => 50,
				'max'        => 100,
				'definition' => 'How much of the page, in percent each way, its one image must cover for the page to count as a scan.',
			],
			// One thread was fastest for a thumbnail, measured with libvips
			// 8.15; each thread reserves memory under the cap besides.
			self::VIPS_CONCURRENCY           => [
				'default'    => 1,
				'min'        => 1,
				'max'        => 64,
				'definition' => 'Threads vipsthumbnail may use for one render (--vips-concurrency); the header, a conversion and tiffcp run with their own defaults.',
			],
			// Nextcloud stores a preview in the type of the image it is
			// given: streamed, as rendered; otherwise re-encoded at its own
			// quality (preview jpeg_quality and webp_quality).
			self::PREVIEW_FORMAT             => [
				'default'    => 'nextcloud',
				'choices'    => [ 'nextcloud', 'png', 'jpeg', 'webp' ],
				'definition' => 'The type previews are stored in; nextcloud follows the system setting preview_format, WebP where it says webp and JPEG otherwise. A PDF previewed as WebP without libvips is JPEG.',
			],
			self::PREVIEW_BACKGROUND         => [
				'default'    => 'white',
				'choices'    => [ 'white', 'black' ],
				'definition' => 'What a transparent image is laid on in a JPEG preview, which keeps no transparency.',
			],
			// 95, as the renderers wrote before it could be set: a re-encoding
			// at Nextcloud's quality hardly adds to its loss.
			self::PREVIEW_QUALITY            => [
				'default'    => 95,
				'min'        => 10,
				'max'        => 100,
				'definition' => 'The quality a JPEG or WebP preview is rendered at; streamed, the largest preview\'s, otherwise only the image Nextcloud encodes again at its own. A PNG has none.',
			],
			// Nextcloud stores a stream image as it is; off, the render is
			// decoded and Nextcloud encodes it again, as before.
			self::PREVIEW_STREAM             => [
				'default'    => true,
				'definition' => 'Whether the largest preview is stored as rendered, handed to Nextcloud as a stream at the app\'s quality, rather than decoded and encoded again at Nextcloud\'s; a render larger than Nextcloud\'s preview box is decoded either way, to be scaled.',
			],
		];


//  getters / setters / is* / has*

	#[\Override]
	public function getStrictness(): Strictness
	{
		return Strictness::WARNING;
	}

	#[\Override]
	public function getAppConfigs(): array
	{
		$entries = [];

		foreach ( self::SCALARS as $key => $spec )
		{
			$entries[] = self::entry(
				$key,
				match ( true )
				{
					is_bool( $spec['default'] )   => ValueType::BOOL,
					is_string( $spec['default'] ) => ValueType::STRING,
					default                       => ValueType::INT,
				},
				$spec['default'],
				$spec['definition'] . self::rangeText( $spec ),
			);
		}

		$entries[] = self::entry(
			self::MIMETYPES,
			ValueType::ARRAY,
			self::defaultMimeTypes(),
			'Per MIME type: enabled, min_size_mb and max_size_mb, each optional. A type absent from the map has its shipped default.',
		);
		$entries[] = self::entry(
			self::EXTENSIONS,
			ValueType::ARRAY,
			[],
			'Per lower-case file extension: enabled, min_size_mb and max_size_mb, each optional; an extension overrides its MIME type field by field.',
		);
		// Lazy: read by the setup check and the page, never by a render.
		$entries[] = self::entry(
			self::LAST_FAILURE,
			ValueType::ARRAY,
			[],
			'The last failed render: time, MIME type, outcome, reason and exit status. Written by the app only, and only on failure.',
			true,
		);

		return $entries;
	}

	#[\Override]
	public function getUserConfigs(): array
	{
		return [];
	}


//  static methods

	/**
	 * What each MIME type has until it is set: every type off, so that an
	 * installation changes nothing until an administrator enables what the
	 * app is to take; an image type from the size given once it is on, 50 MB
	 * by default (ConfigurationService::defaultMimeTypes() gives the
	 * server's), and with no minimum of its own where none is given.
	 *
	 * @return array<string, array{enabled: bool, min_size_mb?: int}>
	 */
	public static function defaultMimeTypes( ?int $imageMinSizeMb = self::IMAGE_DEFAULT_MIN_SIZE_MB ): array
	{
		$types = [];

		foreach ( SupportedTypes::DOCUMENT_TYPES as $mime )
		{
			$types[ $mime ] = [ 'enabled' => false ];
		}

		foreach ( SupportedTypes::IMAGE_TYPES as $mime )
		{
			$types[ $mime ] = $imageMinSizeMb === null
				? [ 'enabled' => false ]
				: [
					'enabled'     => false,
					'min_size_mb' => $imageMinSizeMb,
				];
		}

		return $types;
	}

	/**
	 * @param array{default: int|bool|string, min?: int, max?: int|null, choices?: list<string>, definition: string} $spec
	 */
	private static function rangeText( array $spec ): string
	{
		if ( isset( $spec['choices'] ) )
		{
			return sprintf( ' One of %s.', implode( ', ', $spec['choices'] ) );
		}

		if ( ! isset( $spec['min'] ) )
		{
			return '';
		}

		return ( $spec['max'] ?? null ) === null
			? sprintf( ' At least %d.', $spec['min'] )
			: sprintf( ' %d to %d.', $spec['min'], $spec['max'] );
	}

	private static function entry(
		string                $key,
		ValueType             $type,
		int|bool|string|array $default,
		string                $definition,
		bool                  $lazy = false,
	): Entry
	{
		return new Entry(
			key: $key,
			type: $type,
			defaultRaw: $default,
			definition: $definition,
			lazy: $lazy,
			flags: IAppConfig::FLAG_INTERNAL,
		);
	}
}
