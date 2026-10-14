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

	public const MIMETYPES = 'mimetypes';

	public const EXTENSIONS = 'extensions';

	public const TIMEOUT_ENABLED = 'timeout_enabled';

	public const TIMEOUT_SECONDS = 'timeout_seconds';

	public const TIMEOUT_KILL_AFTER_SECONDS = 'timeout_kill_after_seconds';

	public const PRLIMIT_ENABLED = 'prlimit_enabled';

	public const PRLIMIT_AS_MB = 'prlimit_as_mb';

	public const VIPS_PRLIMIT_AS_MB = 'vips_prlimit_as_mb';

	public const CHOOM_ENABLED = 'choom_enabled';

	public const CHOOM_ADJUST = 'choom_adjust';

	public const SCAN_NATIVE_ENABLED = 'scan_native_enabled';

	public const SCAN_COVERAGE_PERCENT = 'scan_coverage_percent';

	public const VIPS_BLOCK_UNTRUSTED = 'vips_block_untrusted';

	public const VIPS_CONCURRENCY = 'vips_concurrency';

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
	 * The settings that are one value each: default, and for a number its
	 * range, `null` where it has no upper bound.
	 *
	 * @var array<string, array{default: int|bool, min?: int, max?: int|null, definition: string}>
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
			self::TIMEOUT_ENABLED            => [
				'default'    => true,
				'definition' => 'Whether each render runs under timeout(1).',
			],
			self::TIMEOUT_SECONDS            => [
				'default'    => 30,
				'min'        => 1,
				'max'        => 600,
				'definition' => 'Seconds a render may take before timeout(1) stops it.',
			],
			self::TIMEOUT_KILL_AFTER_SECONDS => [
				'default'    => 5,
				'min'        => 1,
				'max'        => 60,
				'definition' => 'Seconds timeout(1) waits after SIGTERM before SIGKILL.',
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
			self::CHOOM_ENABLED             => [
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
			self::VIPS_BLOCK_UNTRUSTED       => [
				'default'    => true,
				'definition' => 'Whether libvips runs with its untrusted decoders blocked (VIPS_BLOCK_UNTRUSTED): JPEG 2000, JPEG XL, PNM, and ImageMagick, which BMP needs.',
			],
			// One thread was fastest for a thumbnail, measured with libvips
			// 8.15; each thread reserves memory under the cap besides.
			self::VIPS_CONCURRENCY           => [
				'default'    => 1,
				'min'        => 1,
				'max'        => 64,
				'definition' => 'Threads libvips may use for one render (--vips-concurrency).',
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
				is_bool( $spec['default'] )
					? ValueType::BOOL
					: ValueType::INT,
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
		$entries[] = self::entry(
			self::LAST_FAILURE,
			ValueType::ARRAY,
			[],
			'The last failed render: time, MIME type, reason and exit status. Written by the app only, and only on failure.',
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
	 * What each MIME type has until it is set: PDF and Illustrator on,
	 * every image type off and from the size given, 50 MB by default
	 * (ConfigurationService::defaultMimeTypes() gives the server's).
	 *
	 * @return array<string, array{enabled: bool, min_size_mb?: int}>
	 */
	public static function defaultMimeTypes( int $imageMinSizeMb = self::IMAGE_DEFAULT_MIN_SIZE_MB ): array
	{
		$types = [];

		foreach ( SupportedTypes::DOCUMENT_TYPES as $mime )
		{
			$types[ $mime ] = [ 'enabled' => true ];
		}

		foreach ( SupportedTypes::IMAGE_TYPES as $mime )
		{
			$types[ $mime ] = [
				'enabled'     => false,
				'min_size_mb' => $imageMinSizeMb,
			];
		}

		return $types;
	}

	/**
	 * @param array{default: int|bool, min?: int, max?: int|null, definition: string} $spec
	 */
	private static function rangeText( array $spec ): string
	{
		if ( ! isset( $spec['min'] ) )
		{
			return '';
		}

		return ( $spec['max'] ?? null ) === null
			? sprintf( ' At least %d.', $spec['min'] )
			: sprintf( ' %d to %d.', $spec['min'], $spec['max'] );
	}

	private static function entry(
		string         $key,
		ValueType      $type,
		int|bool|array $default,
		string         $definition,
	): Entry
	{
		return new Entry(
			key: $key,
			type: $type,
			defaultRaw: $default,
			definition: $definition,
			lazy: false,
			flags: IAppConfig::FLAG_INTERNAL,
		);
	}
}
