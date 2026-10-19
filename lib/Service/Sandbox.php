<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Service;

use OCA\LargeFilePreviews\Config\ConfigLexicon;

/**
 * Each render in a bubblewrap sandbox, where `bwrap_enabled` says so: the
 * innermost wrapper of CommandRunner's chain, so that the caps, the OOM
 * score and the timeout hold for it too.
 *
 *     bwrap --unshare-all --die-with-parent --new-session
 *           --ro-bind /usr /usr  --symlink usr/lib /lib …  --ro-bind /etc /etc
 *           --ro-bind /var/cache/fontconfig /var/cache/fontconfig
 *           --proc /proc --dev /dev --tmpfs /tmp
 *           [--ro-bind <program's folder> …]  [--bind <render's folder> …]  --  <program> …
 *
 * What a renderer reaches without it is what the web server reaches: every
 * user's files, config.php and its database password, the network. Inside,
 * it has fresh namespaces - no network, its own processes - and of the file
 * system the system's programs and libraries, `/etc`, read-only, and the
 * render's own folder (RunOptions::$writable): nothing else exists there.
 * The user's file is handed on as a descriptor, as without it: inherited,
 * seekable, never copied.
 *
 * The top-level directories `/bin`, `/sbin` and the `/lib`s are symlinks
 * into `/usr` on a merged-/usr system and directories elsewhere; each is
 * made inside as it is outside. A program a path in system config puts
 * outside them has its own folder bound read-only; libraries it loads from
 * elsewhere are not there. With `/var` hidden, fontconfig's cache is bound
 * read-only where it exists, rather than rebuilt by every render.
 *
 * bwrap needs user namespaces, which a container usually refuses, and which
 * a distribution may restrict: whether it can start a sandbox here is
 * probed once and kept (DetectionCache). Switched on and unable, a run
 * fails before it starts, as a missing limit's does.
 */
class Sandbox
{

//  constants

	public const PROGRAM = 'bwrap';

	/** Where the system's programs and libraries may be besides `/usr`. */
	public const TOP_LEVEL = [ '/bin', '/sbin', '/lib', '/lib32', '/lib64', '/libx32' ];

	public const FONT_CACHE = '/var/cache/fontconfig';

	/** Bumped where the arguments change, so a probe of the old ones is not kept. */
	private const PROBE_VERSION = 1;


//  constructor

	public function __construct(
		private readonly BinaryLocator  $binaries,
		private readonly DetectionCache $cache,
	)
	{
	}


//  other non-static methods

	/**
	 * bwrap and its arguments, through the `--` before the program, or why
	 * the run cannot start in a sandbox.
	 *
	 * @param string      $program  the program's path
	 * @param string|null $writable the render's folder
	 * @return list<string>|RenderOutcome
	 */
	public function wrap(
		string  $program,
		?string $writable,
	): array|RenderOutcome
	{
		$location = $this->binaries->locate( self::PROGRAM );

		if ( ! $location->isFound() )
		{
			return new RenderOutcome(
				Outcome::LIMIT_MISSING,
				sprintf(
					'bwrap is switched on but not found%s; switch it off (%s) or install it',
					$location->problem === null ? '' : ': ' . $location->problem,
					ConfigLexicon::BWRAP_ENABLED,
				),
			);
		}

		$problem = $this->problem();

		if ( $problem !== null )
		{
			return new RenderOutcome(
				Outcome::LIMIT_MISSING,
				sprintf( 'bwrap is switched on but cannot start a sandbox here: %s; switch it off (%s)', $problem, ConfigLexicon::BWRAP_ENABLED ),
			);
		}

		return [
			(string) $location->path,
			...self::arguments( self::layout(), $program, $writable, is_dir( self::FONT_CACHE ) ),
			'--',
		];
	}

	/**
	 * Why bwrap cannot start a sandbox here, or null where it can: a
	 * sandbox started with the arguments a render's would have, once, and
	 * its answer kept with the program's path and change time.
	 */
	public function problem(): ?string
	{
		$location = $this->binaries->locate( self::PROGRAM );

		if ( ! $location->isFound() )
		{
			return 'bwrap is not found';
		}

		$path = (string) $location->path;
		$key  = self::probeKey( $path );
		$kept = $this->cache->get( $key );

		if ( $kept !== null )
		{
			return $kept === '' ? null : $kept;
		}

		$problem = self::probe( $path );
		$this->cache->set( $key, $problem ?? '' );

		return $problem;
	}


//  static methods

	/**
	 * The arguments between bwrap and the `--` before the program.
	 *
	 * @param array<string, string> $layout    each top-level directory there
	 *                                         is: its symlink's target, or ''
	 *                                         for a directory (layout())
	 * @param string                $program   the program's path
	 * @param string|null           $writable  the render's folder
	 * @param bool                  $fontCache whether fontconfig's cache is there
	 * @return list<string>
	 */
	public static function arguments(
		array   $layout,
		string  $program,
		?string $writable,
		bool    $fontCache,
	): array
	{
		$arguments = [ '--unshare-all', '--die-with-parent', '--new-session', '--ro-bind', '/usr', '/usr' ];

		foreach ( $layout as $directory => $target )
		{
			array_push( $arguments, ...( $target === '' ? [ '--ro-bind', $directory, $directory ] : [ '--symlink', $target, $directory ] ) );
		}

		array_push( $arguments, '--ro-bind', '/etc', '/etc' );

		if ( $fontCache )
		{
			array_push( $arguments, '--ro-bind', self::FONT_CACHE, self::FONT_CACHE );
		}

		array_push( $arguments, '--proc', '/proc', '--dev', '/dev', '--tmpfs', '/tmp' );

		$folder = dirname( $program );

		if ( ! self::within( $folder, [ '/usr', ...array_keys( $layout ) ] ) )
		{
			array_push( $arguments, '--ro-bind', $folder, $folder );
		}

		if ( $writable !== null )
		{
			array_push( $arguments, '--bind', $writable, $writable );
		}

		return $arguments;
	}

	/**
	 * Where the probe's answer for a bwrap is kept: by its path and change
	 * time, so that another bwrap, or the same one updated, is probed again.
	 */
	public static function probeKey( string $bwrap ): string
	{
		return sprintf( 'sandbox.%d.%s', self::PROBE_VERSION, md5( $bwrap . '|' . (string) @filemtime( $bwrap ) ) );
	}

	/**
	 * The top-level directories this system has: each symlink with its
	 * target, each directory with ''.
	 *
	 * @return array<string, string>
	 */
	public static function layout(): array
	{
		$layout = [];

		foreach ( self::TOP_LEVEL as $directory )
		{
			if ( is_link( $directory ) )
			{
				$layout[ $directory ] = (string) readlink( $directory );
			}
			elseif ( is_dir( $directory ) )
			{
				$layout[ $directory ] = '';
			}
		}

		return $layout;
	}

	/**
	 * A sandbox that runs `true`: null where it starts, otherwise what bwrap
	 * said.
	 */
	private static function probe( string $bwrap ): ?string
	{
		if ( ! function_exists( 'proc_open' ) )
		{
			return 'proc_open() is disabled';
		}

		$true    = is_file( '/usr/bin/true' ) ? '/usr/bin/true' : '/bin/true';
		$command = [ $bwrap, ...self::arguments( self::layout(), $true, null, false ), '--', $true ];
		$process = @proc_open( $command, [ 0 => [ 'file', '/dev/null', 'r' ], 1 => [ 'file', '/dev/null', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );

		if ( ! is_resource( $process ) )
		{
			return 'bwrap could not be started';
		}

		$stderr = trim( (string) stream_get_contents( $pipes[2], 1024 ) );
		fclose( $pipes[2] );
		$status = proc_close( $process );

		if ( $status === 0 )
		{
			return null;
		}

		return $stderr === '' ? sprintf( 'exit status %d', $status ) : $stderr;
	}

	/**
	 * Whether a folder is one of those given, or below one.
	 *
	 * @param list<string> $roots
	 */
	private static function within(
		string $folder,
		array  $roots,
	): bool
	{
		foreach ( $roots as $root )
		{
			if ( $folder === $root || str_starts_with( $folder . '/', $root . '/' ) )
			{
				return true;
			}
		}

		return false;
	}
}
