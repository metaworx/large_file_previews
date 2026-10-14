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

	public const PROGRAMS
		 = [
			'pdftoppm',
			'pdfinfo',
			'pdfimages',
			'vipsthumbnail',
			'vipsheader',
			'vips',
			'tiffcp',
			'timeout',
			'prlimit',
			'choom',
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

		foreach ( self::PROGRAMS as $program )
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
			if ( ! in_array( $program, self::PROGRAMS, true ) )
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
		$path = $this->cache->get( 'path.' . $program );

		if ( $path === null )
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
	 * Forgets every path found, in this request and in the cache.
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

		$problem = match ( true )
		{
			! str_starts_with( $path, '/' ) => 'is not an absolute path',
			! is_file( $path )              => 'does not exist',
			! is_executable( $path )        => 'is not executable',
			default                         => null,
		};

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
	 * @throws ConfigurationException
	 */
	private static function refuseUnknown( string $program ): void
	{
		if ( in_array( $program, self::PROGRAMS, true ) )
		{
			return;
		}

		$message = sprintf( '%s is not a program this app runs; the programs are %s.', $program, implode( ', ', self::PROGRAMS ) );

		throw new ConfigurationException( $message, $message );
	}
}
