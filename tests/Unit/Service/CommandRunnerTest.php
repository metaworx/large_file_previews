<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Unit\Service;

use OCA\LargeFilePreviews\Config\ConfigLexicon;
use OCA\LargeFilePreviews\Config\Limits;
use OCA\LargeFilePreviews\Service\BinaryLocator;
use OCA\LargeFilePreviews\Service\CommandRunner;
use OCA\LargeFilePreviews\Service\ConfigurationService;
use OCA\LargeFilePreviews\Service\Outcome;
use OCA\LargeFilePreviews\Service\RenderOutcome;
use OCA\LargeFilePreviews\Tests\Unit\EnglishL10n;
use OCA\LargeFilePreviews\Tests\Unit\MemoryAppConfig;
use OCP\IBinaryFinder;
use OCP\IConfig;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The runner against stub programs in tests/fixtures/bin, under the real
 * timeout, prlimit and choom where the machine has them.
 */
class CommandRunnerTest
    extends
    TestCase
{
	use EnglishL10n;
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
			[ '/t', '--kill-after=5s', '30s', '/p', '--as=536870912', '--', '/c', '-n', '700', '--' ],
			$runner->prefix( new Limits( true, 30, 5, true, 512, true, 700 ) ),
		);
	}

	public function testEachLimitCanBeSwitchedOff(): void
	{
		$runner = $this->runner( [ 'timeout' => '/t', 'prlimit' => '/p', 'choom' => '/c' ] );

		self::assertSame( [], $runner->prefix( new Limits( false, 30, 5, false, 512, false, 700 ) ) );
		self::assertSame( [ '/p', '--as=268435456', '--' ], $runner->prefix( new Limits( false, 30, 5, true, 256, false, 0 ) ) );
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

		$finder = $this->createMock( IBinaryFinder::class );
		$finder->method( 'findBinaryPath' )
		       ->willReturnCallback(
			       static fn(
				       string $program,
			       ): string|false => $programs[ $program ]
				       ?? ( is_file( $stubs . '/' . $program ) ? $stubs . '/' . $program : false ),
		       )
		;

		$config = $this->createMock( IConfig::class );
		$config->method( 'getSystemValue' )
		       ->willReturn( [] )
		;

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

		return new CommandRunner(
			new ConfigurationService( $this->memoryAppConfig(), $this->englishL10n() ),
			new BinaryLocator( $config, $finder, $this->createMock( LoggerInterface::class ) ),
			$tempManager,
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
