<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Service;

use OCA\LargeFilePreviews\Config\Limits;
use OCP\ITempManager;

/**
 * Runs a program under the limits the configuration switches on:
 *
 *     timeout --kill-after=<K>s <T>s  prlimit --as=<BYTES> --  choom -n <ADJ> --  <program> …
 *
 * `timeout` is outermost, so it can stop the whole chain, and follows SIGTERM
 * with SIGKILL after its grace period. `prlimit` caps the address space and
 * execs the rest, which inherits the cap: a runaway allocation fails inside
 * the program, and the kernel's OOM killer is not involved. `choom` raises
 * the OOM score, so that if memory runs short anyway the program dies before
 * a web server worker; an unprivileged process may raise it only.
 *
 * The program is started with proc_open() and an argument array: no shell
 * takes part, so nothing in a path needs quoting. stdout goes nowhere, since
 * the programs write files, and stderr to a temporary file, kept to its
 * first few KB: neither pipe can fill and stall the run. A limit switched on
 * whose program is missing fails the run before it starts.
 */
class CommandRunner
{

//  constants

	public const STDERR_LIMIT = 4096;

	/** timeout(1)'s status when it stopped the command with SIGTERM. */
	public const TIMEOUT_STATUS = 124;

	/** 128 + SIGKILL: timeout(1)'s, or a shell's, status for a command killed. */
	public const KILLED_STATUS = 137;

	private const FUNCTIONS
		 = [
			'proc_open',
			'proc_get_status',
			'proc_close',
		];

	private const POLL_MICROSECONDS = 10_000;


//  constructor

	public function __construct(
		private readonly ConfigurationService $config,
		private readonly BinaryLocator        $binaries,
		private readonly ITempManager         $tempManager,
	)
	{
	}


//  config/init/exe/run methods

	/**
	 * Runs a program by name, under the configured limits.
	 *
	 * @param list<string>          $arguments
	 * @param bool                  $keepStdout  for a program that answers on
	 *                                           stdout: kept with stderr, in the
	 *                                           outcome's `stderr`, cut short
	 *                                           as it is
	 * @param array<string, string> $environment variables the program needs,
	 *                                           besides the C locale it always
	 *                                           runs in
	 * @param int|null              $prlimitAsMb a renderer's own address-space
	 *                                           cap, in place of the configured
	 *                                           one; `prlimit_enabled` still
	 *                                           decides whether there is a cap
	 */
	public function run(
		string $program,
		array  $arguments,
		bool   $keepStdout = false,
		array  $environment = [],
		?int   $prlimitAsMb = null,
	): RenderOutcome
	{
		$command = $this->command( $program, $arguments, $prlimitAsMb );

		if ( $command instanceof RenderOutcome )
		{
			return $command;
		}

		if ( ! self::canStartProcesses() )
		{
			return new RenderOutcome(
				Outcome::PROC_OPEN_UNAVAILABLE,
				sprintf( '%s is disabled (disable_functions)', implode( ', ', self::FUNCTIONS ) ),
			);
		}

		return $this->execute( $command, $keepStdout, $environment );
	}


//  other non-static methods

	/**
	 * The whole argument list a run would start, or why it cannot start.
	 *
	 * @param list<string> $arguments
	 * @return list<string>|RenderOutcome
	 */
	public function command(
		string $program,
		array  $arguments,
		?int   $prlimitAsMb = null,
	): array|RenderOutcome
	{
		$location = $this->binaries->locate( $program );

		if ( ! $location->isFound() )
		{
			return new RenderOutcome(
				Outcome::PROGRAM_MISSING,
				sprintf( '%s not found%s', $program, $location->problem === null ? '' : ': ' . $location->problem ),
			);
		}

		$limits = $this->config->limits();
		$prefix = $this->prefix(
			$prlimitAsMb === null
				? $limits
				: $limits->withPrlimitAsMb( $prlimitAsMb ),
		);

		if ( $prefix instanceof RenderOutcome )
		{
			return $prefix;
		}

		return [ ...$prefix, (string) $location->path, ...$arguments ];
	}

	/**
	 * The limits' part of the argument list, outermost first.
	 *
	 * @return list<string>|RenderOutcome
	 */
	public function prefix( Limits $limits ): array|RenderOutcome
	{
		$prefix = [];

		$parts = [
			'timeout' => $limits->timeoutEnabled
				? [
					sprintf( '--kill-after=%ds', $limits->timeoutKillAfterSeconds ),
					sprintf( '%ds', $limits->timeoutSeconds ),
				]
				: null,
			'prlimit' => $limits->prlimitEnabled
				? [
					sprintf( '--as=%d', $limits->prlimitAsMb * 1_048_576 ),
					'--',
				]
				: null,
			'choom'   => $limits->choomEnabled
				? [
					'-n',
					(string) $limits->choomAdjust,
					'--',
				]
				: null,
		];

		foreach ( $parts as $program => $arguments )
		{
			if ( $arguments === null )
			{
				continue;
			}

			$location = $this->binaries->locate( $program );

			if ( ! $location->isFound() )
			{
				return new RenderOutcome(
					Outcome::LIMIT_MISSING,
					sprintf(
						'%s is switched on but not found%s; switch it off (%s_enabled) or install it',
						$program,
						$location->problem === null ? '' : ': ' . $location->problem,
						$program,
					),
				);
			}

			$prefix = [ ...$prefix, (string) $location->path, ...$arguments ];
		}

		return $prefix;
	}

	/**
	 * Runs a program by name with no limits around it, its stdout kept with
	 * its stderr: for a version, which some programs print to one and some
	 * to the other, or a listing. Not for rendering.
	 *
	 * @param list<string> $arguments
	 * @param int          $limit     how much of the output is read
	 */
	public function runBare(
		string $program,
		array  $arguments,
		int    $limit = self::STDERR_LIMIT,
	): RenderOutcome
	{
		$location = $this->binaries->locate( $program );

		if ( ! $location->isFound() )
		{
			return new RenderOutcome( Outcome::PROGRAM_MISSING, sprintf( '%s not found', $program ) );
		}

		if ( ! self::canStartProcesses() )
		{
			return new RenderOutcome( Outcome::PROC_OPEN_UNAVAILABLE, 'proc_open() is disabled' );
		}

		return $this->execute( [ (string) $location->path, ...$arguments ], true, [], $limit );
	}

	/**
	 * @param list<string>          $command
	 * @param array<string, string> $environment
	 */
	private function execute(
		array $command,
		bool  $keepStdout = false,
		array $environment = [],
		int   $limit = self::STDERR_LIMIT,
	): RenderOutcome
	{
		$stderrFile = $this->tempManager->getTemporaryFile( '.stderr' );

		if ( $stderrFile === false )
		{
			return new RenderOutcome( Outcome::START_FAILED, 'no temporary file for stderr' );
		}

		try
		{
			$process = @proc_open(
				$command,
				[
					0 => [ 'file', '/dev/null', 'r' ],
					1 => $keepStdout
						? [ 'file', $stderrFile, 'a' ]
						: [ 'file', '/dev/null', 'w' ],
					2 => [ 'file', $stderrFile, 'a' ],
				],
				$pipes,
				null,
				// The C locale, for messages the log can be searched for;
				// a renderer cannot change it.
				[ ...$environment, 'LC_ALL' => 'C' ],
			);

			if ( ! is_resource( $process ) )
			{
				return new RenderOutcome( Outcome::START_FAILED, sprintf( 'proc_open() could not start %s', $command[0] ) );
			}

			[ $exitStatus, $signal ] = self::wait( $process );
			$stderr                  = self::readStart( $stderrFile, $limit );
		}
		finally
		{
			@unlink( $stderrFile );
		}

		return self::judge( $exitStatus, $signal, $stderr );
	}


//  static methods

	/**
	 * Whether processes can be started at all: `disable_functions` hides
	 * a function from function_exists().
	 */
	public static function canStartProcesses(): bool
	{
		foreach ( self::FUNCTIONS as $function )
		{
			if ( ! function_exists( $function ) )
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * How a run ended, from its exit status or the signal that ended it.
	 */
	public static function judge(
		?int   $exitStatus,
		?int   $signal,
		string $stderr,
	): RenderOutcome
	{
		return match ( true )
		{
			$signal !== null                            => new RenderOutcome(
				Outcome::KILLED,
				sprintf( 'killed by signal %d', $signal ),
				$exitStatus,
				$signal,
				$stderr,
			),
			$exitStatus === 0                           => new RenderOutcome( Outcome::SUCCEEDED, 'exit status 0', 0, null, $stderr ),
			$exitStatus === self::TIMEOUT_STATUS        => new RenderOutcome(
				Outcome::TIMED_OUT,
				'timed out (exit status 124)',
				$exitStatus,
				null,
				$stderr,
			),
			$exitStatus === self::KILLED_STATUS         => new RenderOutcome(
				Outcome::KILLED,
				'killed after the timeout\'s grace period (exit status 137)',
				$exitStatus,
				9,
				$stderr,
			),
			default                                     => new RenderOutcome(
				Outcome::FAILED,
				sprintf( 'exit status %s', $exitStatus ?? 'unknown' ),
				$exitStatus,
				null,
				$stderr,
			),
		};
	}

	/**
	 * Waits for a process to end: its exit status, or the signal that
	 * ended it.
	 *
	 * Polled through proc_get_status(), which alone tells a signal from an
	 * exit; proc_close() then only releases it.
	 *
	 * @param resource $process
	 * @return array{?int, ?int}
	 */
	private static function wait( $process ): array
	{
		while ( true )
		{
			$status = proc_get_status( $process );

			if ( ! $status['running'] )
			{
				break;
			}

			usleep( self::POLL_MICROSECONDS );
		}

		proc_close( $process );

		if ( $status['signaled'] )
		{
			return [ null, $status['termsig'] ];
		}

		return [ $status['exitcode'] >= 0 ? $status['exitcode'] : null, null ];
	}

	private static function readStart(
		string $file,
		int    $limit,
	): string
	{
		$text = @file_get_contents( $file, false, null, 0, max( 1, $limit ) );

		return is_string( $text )
			? trim( $text )
			: '';
	}
}
