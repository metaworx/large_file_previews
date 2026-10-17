<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Service;

use OCA\LargeFilePreviews\Config\ConfigurationException;
use OCP\IBinaryFinder;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Finds the programs the app runs.
 *
 * A path can be overridden, but only in system config:
 *
 *     'large_file_previews.binaries' => ['pdftoppm' => '/opt/poppler/bin/pdftoppm'],
 *
 * in config.php, with `occ large-file-previews:config:set binaries.<program>`
 * (setOverride()), or in a file of its own, `config/*.config.php`, which
 * Nextcloud merges into its system config. Never in app config: an
 * administrator can write that over the provisioning API without shell
 * access, and a path there would be code execution as the web server's
 * user. Whoever can write config.php can run code already.
 *
 * An override is used as it is or not at all. One that is not absolute,
 * missing or not executable leaves its program missing, so a limit that
 * needs it fails closed, rather than the finder's copy running in its place:
 * the administrator asked for that binary.
 *
 * What the finder answers is kept in DetectionCache, for a day or until
 * refresh(); an override is read from system config every time.
 */
class BinaryLocator
{

//  constants

	public const SYSTEM_KEY = 'large_file_previews.binaries';

	/**
	 * Every program the app runs, the one list of them: how it says which
	 * version it is, when it is needed - null always, false never, or while
	 * a limit or the images are on - what for, and the source package
	 * that brings it (Platform).
	 *
	 * @var array<string, array{version: string, limit: string|false|null, for: string, source: string}>
	 */
	public const PROGRAMS
		 = [
			'pdftoppm'      => [ 'version' => '-v', 'limit' => null, 'for' => 'rendering PDF and Illustrator files', 'source' => 'poppler' ],
			// Without them a scanned page is enlarged to the full box; no reason to stop rendering.
			'pdfinfo'       => [ 'version' => '-v', 'limit' => false, 'for' => 'rendering a scanned page at its own resolution', 'source' => 'poppler' ],
			'pdfimages'     => [ 'version' => '-v', 'limit' => false, 'for' => 'rendering a scanned page at its own resolution', 'source' => 'poppler' ],
			// Needed while an image type is switched on, as a limit while it is.
			'vipsthumbnail' => [ 'version' => '--version', 'limit' => 'images', 'for' => 'rendering images', 'source' => 'vips' ],
			// Without it an image smaller than the preview is enlarged.
			'vipsheader'    => [ 'version' => '--version', 'limit' => false, 'for' => 'rendering an image at its own size where that is smaller', 'source' => 'vips' ],
			// Without it, which image types libvips reads is found out by
			// rendering them, and a PDF previewed as WebP is a JPEG.
			'vips'          => [ 'version' => '--version', 'limit' => false, 'for' => 'telling which image types libvips reads, and WebP previews of PDFs', 'source' => 'vips' ],
			// Without it, a TIFF in one large strip that runs out of memory stays a failure.
			'tiffcp'        => [ 'version' => '-h', 'limit' => false, 'for' => 'a second chance for a TIFF in one strip that runs out of memory, with vipsheader', 'source' => 'tiff' ],
			'timeout'       => [ 'version' => '--version', 'limit' => 'timeout', 'for' => 'the timeout of each render', 'source' => 'core' ],
			'prlimit'       => [ 'version' => '--version', 'limit' => 'prlimit', 'for' => 'the address-space and file-size caps of each render', 'source' => 'util' ],
			'choom'         => [ 'version' => '--version', 'limit' => 'choom', 'for' => 'the OOM score of each render', 'source' => 'util' ],
		];

	/** @var array<string, BinaryLocation> */
	private array $located = [];


//  constructor

	public function __construct(
		private readonly IConfig         $config,
		private readonly IBinaryFinder   $finder,
		private readonly DetectionCache  $cache,
		private readonly LoggerInterface $logger,
	)
	{
	}


//  other non-static methods

	public function locate( string $program ): BinaryLocation
	{
		return $this->located[ $program ] ??= $this->find( $program );
	}

	/**
	 * Every program the app runs, located.
	 *
	 * @return array<string, BinaryLocation>
	 */
	public function locateAll(): array
	{
		$all = [];

		foreach ( array_keys( self::PROGRAMS ) as $program )
		{
			$all[ $program ] = $this->locate( $program );
		}

		return $all;
	}

	/**
	 * What in the override setting is not used, in English: a key that
	 * names no program the app runs, or a value that is no path.
	 *
	 * @return list<string>
	 */
	public function problems(): array
	{
		$problems  = [];
		$overrides = $this->config->getSystemValue( self::SYSTEM_KEY, [] );

		if ( ! is_array( $overrides ) )
		{
			return [ sprintf( '%s is not a map from program to path; ignored', self::SYSTEM_KEY ) ];
		}

		foreach ( $overrides as $program => $path )
		{
			if ( ! array_key_exists( $program, self::PROGRAMS ) )
			{
				$problems[] = sprintf( '%s.%s: not a program this app runs; ignored', self::SYSTEM_KEY, $program );
			}
			elseif ( ! is_string( $path ) )
			{
				$problems[] = sprintf( '%s.%s: not a path', self::SYSTEM_KEY, $program );
			}
		}

		return $problems;
	}

	private function find( string $program ): BinaryLocation
	{
		$overrides = $this->config->getSystemValue( self::SYSTEM_KEY, [] );
		$override  = is_array( $overrides ) ? ( $overrides[ $program ] ?? null ) : null;

		if ( $override !== null )
		{
			$problem = match ( true )
			{
				! is_string( $override )            => 'the override is not a path',
				! str_starts_with( $override, '/' ) => 'the override is not an absolute path',
				! is_file( $override )              => 'the override does not exist',
				! is_executable( $override )        => 'the override is not executable',
				default                             => null,
			};

			if ( $problem !== null )
			{
				$this->logger->warning(
					'{program}: {problem}, so it counts as missing ({key})',
					[
						'program' => $program,
						'problem' => $problem,
						'key'     => self::SYSTEM_KEY,
					],
				);

				return new BinaryLocation( $program, null, BinaryLocation::SOURCE_OVERRIDE, $problem );
			}

			/** @var string $override */
			return new BinaryLocation( $program, $override, BinaryLocation::SOURCE_OVERRIDE );
		}

		// What the finder found is kept; an empty string remembers "missing".
		// A kept path is checked again before it is run, as the finder
		// checked it: the cache may be shared with other servers, or written
		// by more than this app.
		$path = $this->cache->get( 'path.' . $program );

		if ( $path === null || ( $path !== '' && ! self::runnable( $path ) ) )
		{
			$found = $this->finder->findBinaryPath( $program );
			$path  = is_string( $found ) ? $found : '';
			$this->cache->set( 'path.' . $program, $path );
		}

		return new BinaryLocation(
			$program,
			$path !== '' ? $path : null,
			BinaryLocation::SOURCE_FINDER,
		);
	}

	/**
	 * Forgets every path found, in this request and in the cache, and with
	 * the cache all else DetectionCache keeps: versions, libvips' decoders,
	 * and the files that failed.
	 */
	public function refresh(): void
	{
		$this->located = [];
		$this->cache->clear();
	}


//  getters / setters / is* / has*

	/**
	 * Sets a program's path in system config, after checking that it is an
	 * executable file. From occ only: the shell is the right that writing
	 * system config takes, and no page offers it.
	 *
	 * @throws ConfigurationException
	 */
	public function setOverride(
		string $program,
		string $path,
	): void
	{
		self::refuseUnknown( $program );

		$problem = self::pathProblem( $path );

		if ( $problem !== null )
		{
			$message = sprintf( '%s %s.', $path, $problem );

			throw new ConfigurationException( $message, $message );
		}

		$overrides             = $this->overrides();
		$overrides[ $program ] = $path;
		ksort( $overrides );

		$this->config->setSystemValue( self::SYSTEM_KEY, $overrides );
		unset( $this->located[ $program ] );
	}

	/**
	 * Removes a program's override; the finder answers for it again.
	 *
	 * @throws ConfigurationException
	 */
	public function unsetOverride( string $program ): void
	{
		self::refuseUnknown( $program );

		$overrides = $this->overrides();
		unset( $overrides[ $program ] );

		$overrides === []
			? $this->config->deleteSystemValue( self::SYSTEM_KEY )
			: $this->config->setSystemValue( self::SYSTEM_KEY, $overrides );

		unset( $this->located[ $program ] );
	}

	/**
	 * The overrides set, the ones that are paths.
	 *
	 * @return array<string, string>
	 */
	public function overrides(): array
	{
		$stored = $this->config->getSystemValue( self::SYSTEM_KEY, [] );

		return is_array( $stored )
			? array_filter( $stored, is_string( ... ) )
			: [];
	}


//  static methods

	/**
	 * What keeps a path from being a program's override, in English, or
	 * null where it is an absolute path to an executable file.
	 */
	public static function pathProblem( string $path ): ?string
	{
		return match ( true )
		{
			! str_starts_with( $path, '/' ) => 'is not an absolute path',
			! is_file( $path )              => 'does not exist',
			! is_executable( $path )        => 'is not executable',
			default                         => null,
		};
	}

	/**
	 * An absolute path to an executable file.
	 */
	private static function runnable( string $path ): bool
	{
		return str_starts_with( $path, '/' ) && is_file( $path ) && is_executable( $path );
	}

	/**
	 * @throws ConfigurationException
	 */
	private static function refuseUnknown( string $program ): void
	{
		if ( array_key_exists( $program, self::PROGRAMS ) )
		{
			return;
		}

		$message = sprintf( '%s is not a program this app runs; the programs are %s.', $program, implode( ', ', array_keys( self::PROGRAMS ) ) );

		throw new ConfigurationException( $message, $message );
	}
}
