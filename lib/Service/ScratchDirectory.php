<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Service;

use OCA\LargeFilePreviews\Config\ConfigurationException;
use OCP\IConfig;
use OCP\ITempManager;

/**
 * Where a large working copy goes: the uncompressed, re-striped copy of a
 * TIFF that ran out of memory, which can take gigabytes.
 *
 * Nextcloud's temporary directory unless system config says otherwise:
 *
 *     'large_file_previews.scratch_directory' => '/var/cache/large_file_previews',
 *
 * set with `occ lfp:config:set scratch_directory <path>`. A path, like a
 * program's, stays out of app config, which an administrator can write
 * without shell access. The directory must be on disk: on tmpfs or ramfs the
 * copy would sit in the memory it was made to spare, so it is refused there,
 * and where it has too little free space.
 */
class ScratchDirectory
{

//  constants

	public const SYSTEM_KEY = 'large_file_previews.scratch_directory';

	/**
	 * Room left besides the copy, so that it never fills the disc.
	 */
	public const RESERVE_BYTES = 268_435_456;

	/** The file one copy at a time locks; it holds nothing. */
	public const LOCK_FILE = '.lfp-restrip.lock';

	/** The file systems that keep their files in memory. */
	private const IN_MEMORY = [ 'tmpfs', 'ramfs' ];


//  constructor

	public function __construct(
		private readonly IConfig      $config,
		private readonly ITempManager $tempManager,
	)
	{
	}


//  other non-static methods

	public function path(): string
	{
		$override = $this->override();

		return rtrim( $override ?? (string) $this->tempManager->getTempBaseDir(), '/' );
	}

	/**
	 * The directory set in system config, or null where none is.
	 */
	public function override(): ?string
	{
		$value = $this->config->getSystemValue( self::SYSTEM_KEY, null );

		return is_string( $value ) && $value !== '' ? $value : null;
	}

	/**
	 * What is wrong with the directory set in system config, in English, or
	 * null where none is set or it is a writable directory: a hand-written
	 * config.php is checked by nothing else before a copy needs it.
	 */
	public function overrideProblem(): ?string
	{
		$value = $this->config->getSystemValue( self::SYSTEM_KEY, null );

		if ( $value === null || $value === '' )
		{
			return null;
		}

		return match ( true )
		{
			! is_string( $value )                         => sprintf( '%s: not a path', self::SYSTEM_KEY ),
			! str_starts_with( $value, '/' )              => sprintf( '%s: %s is not an absolute path', self::SYSTEM_KEY, $value ),
			! is_dir( $value ) || ! is_writable( $value ) => sprintf( '%s: %s is not a writable directory', self::SYSTEM_KEY, $value ),
			default                                       => null,
		};
	}

	/**
	 * Why a copy of the size given cannot go there, in English, or null
	 * where it can.
	 */
	public function problem( int $bytes ): ?string
	{
		$path = $this->path();

		// What cannot be told counts as a problem: a copy that may land in
		// memory, or fill the disc, is not made.
		if ( ! str_starts_with( $path, '/' ) || ! is_dir( $path ) || ! is_writable( $path ) )
		{
			return sprintf( 'the scratch directory %s is not an absolute, writable directory', $path );
		}

		$type = self::fileSystem( $path, (string) @file_get_contents( '/proc/mounts' ) );

		if ( $type === null )
		{
			return sprintf( 'the file system of the scratch directory %s cannot be told: /proc/mounts is not readable', $path );
		}

		if ( in_array( $type, self::IN_MEMORY, true ) )
		{
			return sprintf( 'the scratch directory %s is on %s, in memory; set scratch_directory to one on disk', $path, $type );
		}

		$free = @disk_free_space( $path );

		if ( ! is_float( $free ) )
		{
			return sprintf( 'the free space in the scratch directory %s cannot be told', $path );
		}

		if ( $free < (float) ( $bytes + self::RESERVE_BYTES ) )
		{
			return sprintf( 'the scratch directory %s has %d MB free, and the copy takes %d MB', $path, (int) ( $free / 1_048_576.0 ), (int) ceil( $bytes / 1_048_576 ) );
		}

		return null;
	}

	/**
	 * Takes the scratch directory for one copy at a time, waiting up to the
	 * seconds given: two copies at once could each pass the free-space
	 * check and together fill the disc. Released by closing what it returns;
	 * null where it could not be taken in time.
	 *
	 * @return resource|null
	 */
	public function lock( int $seconds ): mixed
	{
		$handle = @fopen( $this->path() . '/' . self::LOCK_FILE, 'c' );

		if ( $handle === false )
		{
			return null;
		}

		$until = microtime( true ) + (float) max( 0, $seconds );

		do
		{
			if ( flock( $handle, LOCK_EX | LOCK_NB ) )
			{
				return $handle;
			}

			usleep( 100_000 );
		}
		while ( microtime( true ) < $until );

		fclose( $handle );

		return null;
	}

	/**
	 * Sets the directory in system config, after checking that it is one
	 * the web server can write. From occ only.
	 *
	 * @throws ConfigurationException
	 */
	public function set( string $path ): void
	{
		$problem = match ( true )
		{
			! str_starts_with( $path, '/' ) => 'is not an absolute path',
			! is_dir( $path )               => 'is not a directory',
			! is_writable( $path )          => 'is not writable',
			default                         => null,
		};

		if ( $problem !== null )
		{
			$message = sprintf( '%s %s.', $path, $problem );

			throw new ConfigurationException( $message, $message );
		}

		$this->config->setSystemValue( self::SYSTEM_KEY, rtrim( $path, '/' ) );
	}

	public function unset(): void
	{
		$this->config->deleteSystemValue( self::SYSTEM_KEY );
	}


//  static methods

	/**
	 * The type of the file system a path is on, from `/proc/mounts`: the
	 * longest mount point that holds it. Null where nothing is known.
	 */
	public static function fileSystem(
		string $path,
		string $mounts,
	): ?string
	{
		$path  = rtrim( realpath( $path ) ?: $path, '/' ) . '/';
		$found = null;
		$depth = -1;

		foreach ( explode( "\n", $mounts ) as $line )
		{
			$fields = explode( ' ', $line );

			if ( count( $fields ) < 3 )
			{
				continue;
			}

			// Spaces and tabs in a mount point are octal escapes.
			$point = rtrim( stripcslashes( $fields[1] ), '/' ) . '/';

			if ( str_starts_with( $path, $point ) && strlen( $point ) > $depth )
			{
				$found = $fields[2];
				$depth = strlen( $point );
			}
		}

		return $found;
	}
}
