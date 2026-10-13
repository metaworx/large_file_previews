<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Service;

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
 * in config.php, or with `occ config:system:set`. Never in app config: an
 * administrator can write that over the provisioning API without shell
 * access, and a path there would be code execution as the web server's
 * user. Whoever can write config.php can run code already.
 *
 * An override is used as it is or not at all. One that is not absolute,
 * missing or not executable leaves its program missing, so a limit that
 * needs it fails closed, rather than the finder's copy running in its place:
 * the administrator asked for that binary.
 */
class BinaryLocator
{

//  constants

	public const SYSTEM_KEY = 'large_file_previews.binaries';

	public const PROGRAMS
		 = [
			'pdftoppm',
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

		$path = $this->finder->findBinaryPath( $program );

		return new BinaryLocation(
			$program,
			is_string( $path ) && $path !== '' ? $path : null,
			BinaryLocation::SOURCE_FINDER,
		);
	}
}
