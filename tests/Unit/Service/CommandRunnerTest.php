<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Unit\Service;

use OCA\LargeFilePreviews\Config\ConfigLexicon;
use OCA\LargeFilePreviews\Config\Limits;
use OCA\LargeFilePreviews\Service\CommandRunner;
use OCA\LargeFilePreviews\Service\Deadline;
use OCA\LargeFilePreviews\Service\Outcome;
use OCA\LargeFilePreviews\Service\RenderOutcome;
use OCA\LargeFilePreviews\Service\RunOptions;
use OCA\LargeFilePreviews\Service\Sandbox;
use OCA\LargeFilePreviews\Tests\Unit\MemoryAppConfig;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;

/**
 * The runner against stub programs in tests/fixtures/bin, under the real
 * timeout, prlimit and choom where the machine has them.
 */
class CommandRunnerTest
    extends
    TestCase
{
	use MemoryAppConfig;

	/** @var list<string> */
	private array $temporary = [];


//  other non-static methods

	protected function tearDown(): void
	{
		foreach ( $this->temporary as $file )
		{
			@unlink( $file );
		}

		parent::tearDown();
	}

	/**
	 * Each limit in its place, outermost first, with its value.
	 */
	public function testThePrefixWithEveryLimit(): void
	{
		$runner = $this->runner( [ 'timeout' => '/t', 'prlimit' => '/p', 'choom' => '/c' ] );

		self::assertSame(
			[ '/t', '--kill-after=5s', '30s', '/p', '--as=536870912', '--fsize=1073741824', '--', '/c', '-n', '700', '--' ],
			$runner->prefix( new Limits( true, 30, 5, true, 512, true, 700 ) ),
		);
	}

	public function testEachLimitCanBeSwitchedOff(): void
	{
		$runner = $this->runner( [ 'timeout' => '/t', 'prlimit' => '/p', 'choom' => '/c' ] );

		self::assertSame( [], $runner->prefix( new Limits( false, 30, 5, false, 512, false, 700 ) ) );
		self::assertSame( [ '/p', '--as=268435456', '--fsize=67108864', '--' ], $runner->prefix( new Limits( false, 30, 5, true, 256, false, 0, 67_108_864 ) ) );
		self::assertSame( [ '/t', '--kill-after=1s', '2s', '/c', '-n', '0', '--' ], $runner->prefix( new Limits( true, 2, 1, false, 256, true, 0 ) ) );
	}

	/**
	 * A limit asked for and not there stops the run: no limit is not what
	 * was asked for.
	 */
	public function testALimitSwitchedOnButMissingFailsClosed(): void
	{
		$outcome = $this->runner( [ 'timeout' => '/t', 'choom' => '/c' ] )
		                ->prefix( new Limits( true, 30, 5, true, 512, true, 700 ) )
		;

		self::assertInstanceOf( RenderOutcome::class, $outcome );
		self::assertSame( Outcome::LIMIT_MISSING, $outcome->outcome );
		self::assertStringContainsString( 'prlimit is switched on but not found', $outcome->reason );
	}

	public function testTheProgramFollowsThePrefix(): void
	{
		$this->appValues = [
			ConfigLexicon::TIMEOUT_ENABLED => false,
			ConfigLexicon::PRLIMIT_ENABLED => false,
			ConfigLexicon::CHOOM_ENABLED   => true,
		];

		self::assertSame(
			[ '/c', '-n', '1000', '--', '/usr/bin/pdftoppm', '-f', '1', 'in put.pdf' ],
			$this->runner( [ 'choom' => '/c', 'pdftoppm' => '/usr/bin/pdftoppm' ] )->command( 'pdftoppm', [ '-f', '1', 'in put.pdf' ] ),
		);
	}

	/**
	 * The sandbox is innermost: the caps, the OOM score and the timeout
	 * hold for bwrap and what it starts. It binds the run's own folder.
	 */
	public function testTheSandboxIsInnermost(): void
	{
		$this->appValues                                            = self::only( ConfigLexicon::CHOOM_ENABLED ) + [ ConfigLexicon::BWRAP_ENABLED => true ];
		$this->cacheValues[ Sandbox::probeKey( '/usr/bin/bwrap' ) ] = '';

		self::assertSame(
			[
				'/c', '-n', '1000', '--',
				'/usr/bin/bwrap', ...Sandbox::arguments( Sandbox::layout(), '/usr/bin/pdftoppm', '/render', is_dir( Sandbox::FONT_CACHE ) ), '--',
				'/usr/bin/pdftoppm', '-f', '1',
			],
			$this->runner( [ 'choom' => '/c', 'bwrap' => '/usr/bin/bwrap', 'pdftoppm' => '/usr/bin/pdftoppm' ] )
			     ->command( 'pdftoppm', [ '-f', '1' ], new RunOptions( writable: '/render' ) ),
		);
	}

	/**
	 * Switched on, a bwrap that is missing, or cannot start a sandbox here,
	 * stops the run before it starts, as a missing limit does.
	 */
	public function testASandboxSwitchedOnButUnusableFailsClosed(): void
	{
		$this->appValues = self::only( '' ) + [ ConfigLexicon::BWRAP_ENABLED => true ];

		$missing = $this->runner( [ 'pdftoppm' => '/usr/bin/pdftoppm' ] )->command( 'pdftoppm', [] );

		self::assertInstanceOf( RenderOutcome::class, $missing );
		self::assertSame( Outcome::LIMIT_MISSING, $missing->outcome );
		self::assertStringContainsString( 'bwrap is switched on but not found', $missing->reason );

		// The first runner's finder left bwrap known as missing.
		$this->cacheValues                                           = [ Sandbox::probeKey( '/usr/bin/bwrap' ) => 'setting up uid map: Permission denied' ];
		$refused                                                     = $this->runner( [ 'bwrap' => '/usr/bin/bwrap', 'pdftoppm' => '/usr/bin/pdftoppm' ] )->command( 'pdftoppm', [] );

		self::assertInstanceOf( RenderOutcome::class, $refused );
		self::assertSame( Outcome::LIMIT_MISSING, $refused->outcome );
		self::assertStringContainsString( 'cannot start a sandbox here: setting up uid map: Permission denied', $refused->reason );
	}

	/**
	 * A renderer's own cap takes the configured one's place; whether there
	 * is a cap at all is still prlimit_enabled.
	 */
	public function testARenderersCapReplacesTheConfiguredOne(): void
	{
		$programs        = [ 'prlimit' => '/p', 'pdftoppm' => '/usr/bin/pdftoppm' ];
		$this->appValues = self::only( ConfigLexicon::PRLIMIT_ENABLED ) + [ ConfigLexicon::PRLIMIT_AS_MB => 512 ];

		self::assertSame(
			[ '/p', '--as=1073741824', '--fsize=1073741824', '--', '/usr/bin/pdftoppm' ],
			$this->runner( $programs )->command( 'pdftoppm', [], new RunOptions( prlimitAsMb: 1024 ) ),
		);
		self::assertSame(
			[ '/p', '--as=536870912', '--fsize=1073741824', '--', '/usr/bin/pdftoppm' ],
			$this->runner( $programs )->command( 'pdftoppm', [] ),
		);

		// The file-size cap is prlimit_fsize_mb, or a run's own: the copy's.
		$this->appValues[ ConfigLexicon::PRLIMIT_FSIZE_MB ] = 64;

		self::assertSame(
			[ '/p', '--as=536870912', '--fsize=67108864', '--', '/usr/bin/pdftoppm' ],
			$this->runner( $programs )->command( 'pdftoppm', [] ),
		);
		self::assertSame(
			[ '/p', '--as=536870912', '--fsize=12345', '--', '/usr/bin/pdftoppm' ],
			$this->runner( $programs )->command( 'pdftoppm', [], new RunOptions( fsizeBytes: 12345 ) ),
		);

		$this->appValues = self::only( '' );

		self::assertSame( [ '/usr/bin/pdftoppm' ], $this->runner( $programs )->command( 'pdftoppm', [], new RunOptions( prlimitAsMb: 1024 ) ) );
	}

	/**
	 * A run is given the time its render has left, never more than
	 * timeout_seconds; once none is left it is not started. Without
	 * timeout(1) there is nothing to give.
	 */
	public function testARunGetsTheTimeItsRenderHasLeft(): void
	{
		$programs        = [ 'timeout' => '/t', 'pdftoppm' => '/usr/bin/pdftoppm' ];
		$this->appValues = self::only( ConfigLexicon::TIMEOUT_ENABLED ) + [ ConfigLexicon::TIMEOUT_SECONDS => 30 ];

		self::assertSame(
			[ '/t', '--kill-after=5s', '10s', '/usr/bin/pdftoppm' ],
			$this->runner( $programs )->command( 'pdftoppm', [], new RunOptions( deadline: Deadline::after( 10 ) ) ),
		);
		self::assertSame(
			[ '/t', '--kill-after=5s', '30s', '/usr/bin/pdftoppm' ],
			$this->runner( $programs )->command( 'pdftoppm', [], new RunOptions( deadline: Deadline::after( 600 ) ) ),
		);

		$late = $this->runner( $programs )->run( 'pdftoppm', [], new RunOptions( deadline: Deadline::after( 0 ) ) );

		self::assertSame( Outcome::TIMED_OUT, $late->outcome );
		self::assertSame( 'the render\'s time (timeout_seconds) ran out before pdftoppm', $late->reason );

		$this->appValues = self::only( '' );

		self::assertSame( [ '/usr/bin/pdftoppm' ], $this->runner( $programs )->command( 'pdftoppm', [], new RunOptions( deadline: Deadline::after( 0 ) ) ) );
	}

	/**
	 * What a renderer sets reaches the program; the C locale stays.
	 */
	public function testTheEnvironmentReachesTheProgramButNotTheLocale(): void
	{
		$outcome = $this->unlimited()->run(
			'environment',
			[],
			new RunOptions( environment: [ 'LFP_TEST' => 'given', 'LC_ALL' => 'de_CH.UTF-8' ] ),
		);

		self::assertTrue( $outcome->isSuccess(), $outcome->reason );
		self::assertSame( 'C given', $outcome->stderr );
	}

	/**
	 * A file handed on as a descriptor is what the program reads at
	 * /dev/fd/<n>; stdin, stdout and stderr are not to be handed.
	 */
	public function testAFileIsHandedOnAsADescriptor(): void
	{
		$file              = (string) tempnam( sys_get_temp_dir(), 'lfp-' );
		$this->temporary[] = $file;
		file_put_contents( $file, 'the content, not the name' );
		$handle = fopen( $file, 'rb' );

		$outcome = $this->unlimited()->run( 'read-input', [], new RunOptions( descriptors: [ RunOptions::INPUT => $handle ] ) );

		self::assertTrue( $outcome->isSuccess(), $outcome->reason );
		self::assertSame( 'the content, not the name', $outcome->stderr );
		self::assertSame( Outcome::START_FAILED, $this->unlimited()->run( 'read-input', [], new RunOptions( descriptors: [ 1 => $handle ] ) )->outcome );
		self::assertSame( '/dev/fd/4', RunOptions::path( RunOptions::INPUT ) );
	}

	public function testAMissingProgramDoesNotRun(): void
	{
		$outcome = $this->runner( [] )->run( 'pdftoppm', [] );

		self::assertSame( Outcome::PROGRAM_MISSING, $outcome->outcome );
		self::assertSame( 'pdftoppm not found', $outcome->reason );
	}

	public function testSuccessIsTheExitStatusNotAnEmptyStderr(): void
	{
		$outcome = $this->unlimited()->run( 'warn-ok', [] );

		self::assertTrue( $outcome->isSuccess() );
		self::assertSame( 0, $outcome->exitStatus );
		self::assertStringStartsWith( 'Syntax Warning:', $outcome->stderr );
	}

	public function testTheProgramWritesItsFile(): void
	{
		$target            = sys_get_temp_dir() . '/lfp-runner-' . bin2hex( random_bytes( 4 ) ) . '.png';
		$this->temporary[] = $target;

		self::assertTrue( $this->unlimited()->run( 'write-png', [ $target ] )->isSuccess() );
		self::assertSame( [ 2, 2 ], array_slice( (array) getimagesize( $target ), 0, 2 ) );
	}

	public function testAFailureKeepsItsStatusAndMessage(): void
	{
		$outcome = $this->unlimited()->run( 'fail', [] );

		self::assertSame( Outcome::FAILED, $outcome->outcome );
		self::assertSame( 3, $outcome->exitStatus );
		self::assertSame( 'exit status 3', $outcome->reason );
		self::assertSame( 'Syntax Error: Couldn\'t read xref table', $outcome->stderr );
	}

	public function testTimeoutStopsTheRun(): void
	{
		$this->requirePrograms( 'timeout' );
		$this->appValues = self::only( ConfigLexicon::TIMEOUT_ENABLED ) + [ ConfigLexicon::TIMEOUT_SECONDS => 1 ];

		$started = microtime( true );
		$outcome = $this->runner( self::system( 'timeout' ) )->run( 'sleep', [] );

		self::assertSame( Outcome::TIMED_OUT, $outcome->outcome );
		self::assertSame( 124, $outcome->exitStatus );
		self::assertLessThan( 10, microtime( true ) - $started );
	}

	public function testTheGracePeriodEndsInSigkill(): void
	{
		$this->requirePrograms( 'timeout' );
		$this->appValues = self::only( ConfigLexicon::TIMEOUT_ENABLED ) + [
				ConfigLexicon::TIMEOUT_SECONDS            => 1,
				ConfigLexicon::TIMEOUT_KILL_AFTER_SECONDS => 1,
			];

		$outcome = $this->runner( self::system( 'timeout' ) )->run( 'stubborn', [] );

		// GNU timeout raises the signal that ended its command on itself, so
		// the run ends by SIGKILL; another timeout may exit 137 instead.
		self::assertSame( Outcome::KILLED, $outcome->outcome );
		self::assertTrue( $outcome->signal === 9 || $outcome->exitStatus === 137, $outcome->reason );
	}

	public function testTheAddressSpaceCapFailsARunawayAllocation(): void
	{
		$this->requirePrograms( 'prlimit', 'php' );
		$this->appValues = self::only( ConfigLexicon::PRLIMIT_ENABLED ) + [ ConfigLexicon::PRLIMIT_AS_MB => 256 ];

		$outcome = $this->runner( self::system( 'prlimit' ) )->run( 'allocate', [] );

		self::assertSame( Outcome::FAILED, $outcome->outcome );
		self::assertStringContainsString( 'memory', strtolower( $outcome->stderr ) );
	}

	public function testARenderersCapIsTheOneTheRunGets(): void
	{
		$this->requirePrograms( 'prlimit', 'php' );
		$this->appValues = self::only( ConfigLexicon::PRLIMIT_ENABLED ) + [ ConfigLexicon::PRLIMIT_AS_MB => 4096 ];

		$outcome = $this->runner( self::system( 'prlimit' ) )->run( 'allocate', [], new RunOptions( prlimitAsMb: 256 ) );

		self::assertSame( Outcome::FAILED, $outcome->outcome );
		self::assertStringContainsString( 'memory', strtolower( $outcome->stderr ) );
	}

	public function testChoomRaisesTheOomScore(): void
	{
		$this->requirePrograms( 'choom' );

		if ( ! is_readable( '/proc/self/oom_score_adj' ) )
		{
			self::markTestSkipped( 'No /proc/self/oom_score_adj here.' );
		}

		$this->appValues = self::only( ConfigLexicon::CHOOM_ENABLED ) + [ ConfigLexicon::CHOOM_ADJUST => 900 ];

		$outcome = $this->runner( self::system( 'choom' ) )->run( 'oom-score', [] );

		self::assertTrue( $outcome->isSuccess(), $outcome->reason . ' ' . $outcome->stderr );
		self::assertSame( '900', $outcome->stderr );
	}

	/**
	 * For a version: under the timeout alone, so a missing cap does not stop
	 * it, and a hanging program does not hang the request; stdout kept.
	 */
	public function testABareRunHasTheTimeoutOnlyAndKeepsStdout(): void
	{
		$this->requirePrograms( 'timeout' );

		$timeout = (string) self::which( 'timeout' );
		$outcome = $this->runner( [ 'version' => $timeout, 'timeout' => $timeout ] )->runBare( 'version', [ '--version' ] );

		self::assertTrue( $outcome->isSuccess(), $outcome->reason );
		self::assertStringContainsString( 'timeout', $outcome->stderr );
		self::assertSame( Outcome::PROGRAM_MISSING, $this->runner( [] )->runBare( 'pdftoppm', [ '-v' ] )->outcome );

		$this->appValues = [ ConfigLexicon::TIMEOUT_ENABLED => true, ConfigLexicon::TIMEOUT_SECONDS => 1 ];
		$sleep           = $this->runner( [ 'sleep' => (string) self::which( 'sleep' ), 'timeout' => $timeout ] )->runBare( 'sleep', [ '5' ] );

		self::assertSame( Outcome::TIMED_OUT, $sleep->outcome );
	}

	/**
	 * A long output keeps its start and its end, where a program that warns
	 * at length says why it failed.
	 */
	public function testALongOutputKeepsItsStartAndEnd(): void
	{
		$file              = (string) tempnam( sys_get_temp_dir(), 'lfp-' );
		$this->temporary[] = $file;

		file_put_contents( $file, "short\n" );
		self::assertSame( 'short', CommandRunner::readEdges( $file, 64 ) );

		file_put_contents( $file, 'first line' . str_repeat( "\nerror in tile", 100 ) . "\nTIFFFillStrip: No space for data buffer" );
		$text = CommandRunner::readEdges( $file, 100 );

		self::assertStringStartsWith( 'first line', $text );
		self::assertStringContainsString( "\n…\n", $text );
		self::assertStringEndsWith( 'No space for data buffer', $text );
		self::assertLessThanOrEqual( 110, strlen( $text ) );
	}

	public function testJudgeReadsSignalsAndStatuses(): void
	{
		self::assertSame( Outcome::KILLED, CommandRunner::judge( null, 9, '' )->outcome );
		self::assertSame( 9, CommandRunner::judge( null, 9, '' )->signal );
		self::assertSame( Outcome::SUCCEEDED, CommandRunner::judge( 0, null, '' )->outcome );
		self::assertSame( Outcome::TIMED_OUT, CommandRunner::judge( 124, null, '' )->outcome );
		self::assertSame( Outcome::KILLED, CommandRunner::judge( 137, null, '' )->outcome );
		self::assertSame( Outcome::FAILED, CommandRunner::judge( 1, null, '' )->outcome );
		self::assertSame( 'exit status unknown', CommandRunner::judge( null, null, '' )->reason );
	}

	/**
	 * A runner with every limit off, so a stub runs on its own.
	 */
	private function unlimited(): CommandRunner
	{
		$this->appValues = [
			ConfigLexicon::TIMEOUT_ENABLED => false,
			ConfigLexicon::PRLIMIT_ENABLED => false,
			ConfigLexicon::CHOOM_ENABLED   => false,
		];

		return $this->runner( [] );
	}


//  config/init/exe/run methods

	/**
	 * A runner whose finder knows the stubs, and what else is given.
	 *
	 * @param array<string, string> $programs
	 */
	private function runner( array $programs ): CommandRunner
	{
		$stubs = dirname( __DIR__, 2 ) . '/fixtures/bin';

		$tempManager = $this->createMock( ITempManager::class );
		$tempManager->method( 'getTemporaryFile' )
		            ->willReturnCallback(
			            function(): string
			            {
				            $file              = (string) tempnam( sys_get_temp_dir(), 'lfp-' );
				            $this->temporary[] = $file;

				            return $file;
			            },
		            )
		;

		$binaries = $this->binaryLocator(
			static fn(
				string $program,
			): string|false => $programs[ $program ]
				?? ( is_file( $stubs . '/' . $program ) ? $stubs . '/' . $program : false ),
		);

		return new CommandRunner(
			$this->settings(),
			$binaries,
			$tempManager,
			new Sandbox( $binaries, $this->detectionCache(), $this->systemConfig() ),
		);
	}

	private function requirePrograms( string ...$programs ): void
	{
		foreach ( $programs as $program )
		{
			if ( self::which( $program ) === null )
			{
				self::markTestSkipped( $program . ' is not installed here.' );
			}
		}
	}


//  static methods

	/**
	 * Every limit off but the one named.
	 *
	 * @return array<string, bool>
	 */
	private static function only( string $enabledKey ): array
	{
		return [
			ConfigLexicon::TIMEOUT_ENABLED => $enabledKey === ConfigLexicon::TIMEOUT_ENABLED,
			ConfigLexicon::PRLIMIT_ENABLED => $enabledKey === ConfigLexicon::PRLIMIT_ENABLED,
			ConfigLexicon::CHOOM_ENABLED   => $enabledKey === ConfigLexicon::CHOOM_ENABLED,
		];
	}

	/**
	 * @return array<string, string>
	 */
	private static function system( string $program ): array
	{
		return [ $program => (string) self::which( $program ) ];
	}

	private static function which( string $program ): ?string
	{
		foreach ( [ '/usr/bin', '/bin', '/usr/local/bin' ] as $directory )
		{
			if ( is_executable( $directory . '/' . $program ) )
			{
				return $directory . '/' . $program;
			}
		}

		return null;
	}
}
