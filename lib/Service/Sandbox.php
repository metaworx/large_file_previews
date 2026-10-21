<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Service;

use OCA\LargeFilePreviews\Config\ConfigLexicon;
use OCP\IConfig;

/**
 * Each render in a bubblewrap sandbox, where `sandbox` says so - `auto`
 * where the process rendering can start one, `on` always, `off` never: the
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
 * Nextcloud's config and data directory are hidden under an empty folder
 * where they lie inside what is shown, as a distribution's package may
 * keep config.php under /etc (masks()).
 *
 * bwrap needs user namespaces, which a container usually refuses, and which
 * a distribution may restrict: whether it can start a sandbox is probed
 * once and kept (DetectionCache), for the process's user and PHP's mode -
 * `occ`, perhaps as root, may where the web server's worker may not. On and
 * unable, a run fails before it starts, as a missing limit's does; auto and
 * unable, it runs without (CommandRunner).
 */
class Sandbox
{

//  constants

	public const PROGRAM = 'bwrap';

	/** Where the system's programs and libraries may be besides `/usr`. */
	public const TOP_LEVEL = [ '/bin', '/sbin', '/lib', '/lib32', '/lib64', '/libx32' ];

	public const FONT_CACHE = '/var/cache/fontconfig';

	/** Bumped where the arguments change, so a probe of the old ones is not kept. */
	private const PROBE_VERSION = 2;


//  constructor

	public function __construct(
		private readonly BinaryLocator  $binaries,
		private readonly DetectionCache $cache,
		private readonly IConfig        $system,
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
					'the sandbox is on but bwrap is not found%s; install it, or set %s to auto or off',
					$location->problem === null ? '' : ': ' . $location->problem,
					ConfigLexicon::SANDBOX,
				),
			);
		}

		$problem = $this->problem();

		if ( $problem !== null )
		{
			return new RenderOutcome(
				Outcome::LIMIT_MISSING,
				sprintf( 'the sandbox is on but bwrap cannot start one here: %s; set %s to auto or off', self::explained( $problem ), ConfigLexicon::SANDBOX ),
			);
		}

		return [
			(string) $location->path,
			...self::arguments( self::layout(), $program, $writable, is_dir( self::FONT_CACHE ), $this->masks() ),
			'--',
		];
	}

	/**
	 * What must not be seen even where it lies inside what the sandbox
	 * shows: Nextcloud's config, which a distribution's package may keep
	 * under /etc, and its data directory. The config files are the ones
	 * Nextcloud has read, `config.php` and every `*.config.php`, as this
	 * request included them: Nextcloud has no public word for where they
	 * are. Each folder as named and as resolved.
	 *
	 * @return list<string>
	 */
	public function masks(): array
	{
		$folders = [];

		foreach ( get_included_files() as $file )
		{
			$name = basename( $file );

			if ( $name === 'config.php' || str_ends_with( $name, '.config.php' ) )
			{
				$folders[] = dirname( $file );
			}
		}

		$data = rtrim( $this->system->getSystemValueString( 'datadirectory', '' ), '/' );

		if ( $data !== '' )
		{
			$folders[] = $data;
		}

		$masks = [];

		foreach ( $folders as $folder )
		{
			$masks[] = $folder;
			$real    = realpath( $folder );

			if ( $real !== false )
			{
				$masks[] = $real;
			}
		}

		return array_values( array_unique( $masks ) );
	}

	/**
	 * Why bwrap cannot start a sandbox here, or null where it can: a
	 * sandbox started with the arguments a render's would have, once, and
	 * its answer kept with the program's path and change time, for this
	 * process's user and PHP's mode.
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

		$problem = self::probe( $path, $this->masks() );
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
	 * @param list<string>          $masks     folders hidden where they lie
	 *                                         inside what is shown (masks())
	 * @return list<string>
	 */
	public static function arguments(
		array   $layout,
		string  $program,
		?string $writable,
		bool    $fontCache,
		array   $masks = [],
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

		// An empty folder over each, where a bind above shows it.
		foreach ( $masks as $mask )
		{
			if ( self::within( $mask, [ '/usr', '/etc', ...array_keys( $layout ) ] ) )
			{
				array_push( $arguments, '--tmpfs', $mask );
			}
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
	 * A probe's answer with what an administrator can do about it, where
	 * that is known: Ubuntu 24.04's AppArmor lets bwrap create its
	 * namespaces but not bring up their loopback.
	 */
	public static function explained( string $problem ): string
	{
		return str_contains( $problem, 'RTM_NEWADDR' )
			? $problem . ' (Ubuntu restricts user namespaces through AppArmor; the README\'s "The sandbox on Ubuntu 24.04" says how to allow them for bwrap)'
			: $problem;
	}

	/**
	 * Where the probe's answer for a bwrap is kept: by its path and change
	 * time, so that another bwrap, or the same one updated, is probed again,
	 * and by the process it answers for (process()).
	 */
	public static function probeKey(
		string  $bwrap,
		?string $process = null,
	): string
	{
		return sprintf( 'sandbox.%d.%s', self::PROBE_VERSION, md5( $bwrap . '|' . (string) @filemtime( $bwrap ) . '|' . ( $process ?? self::process() ) ) );
	}

	/**
	 * The process a probe answers for: its effective user and PHP's mode.
	 * Root may create user namespaces where www-data may not, and a web
	 * server's worker may be refused them by its service's hardening where
	 * the command line is not.
	 */
	public static function process(): string
	{
		return sprintf( '%s:%s', function_exists( 'posix_geteuid' ) ? (string) posix_geteuid() : '?', PHP_SAPI );
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
	 *
	 * @param list<string> $masks
	 */
	private static function probe(
		string $bwrap,
		array  $masks,
	): ?string
	{
		if ( ! function_exists( 'proc_open' ) )
		{
			return 'proc_open() is disabled';
		}

		$true    = is_file( '/usr/bin/true' ) ? '/usr/bin/true' : '/bin/true';
		$command = [ $bwrap, ...self::arguments( self::layout(), $true, null, false, $masks ), '--', $true ];
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
