<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Unit\Renderer;

use OCA\LargeFilePreviews\Renderer\RunJudge;
use OCA\LargeFilePreviews\Service\Outcome;
use OCA\LargeFilePreviews\Service\RenderOutcome;
use PHPUnit\Framework\TestCase;

class RunJudgeTest
    extends
    TestCase
{

//  constants

	private const PHRASES = [ 'out of memory', 'No space for data buffer' ];


//  other non-static methods

	/**
	 * Out of memory where the program said so, whatever its exit status;
	 * a timeout stays a timeout.
	 */
	public function testOutOfMemoryWhateverTheExitStatus(): void
	{
		foreach ( [
			new RenderOutcome( Outcome::SUCCEEDED, 'exit status 0', 0, null, 'out of memory' ),
			new RenderOutcome( Outcome::FAILED, 'exit status 1', 1, null, "a\nNo space for data buffer at scanline 0" ),
			new RenderOutcome( Outcome::KILLED, 'killed by signal 6', null, 6, 'out of memory' ),
		] as $outcome )
		{
			$judged = self::judged( $outcome );

			self::assertSame( Outcome::OUT_OF_MEMORY, $judged->outcome, $outcome->reason );
			self::assertSame( 'out of memory under the address-space cap (vips_prlimit_as_mb)', $judged->reason );
		}

		$timedOut = new RenderOutcome( Outcome::TIMED_OUT, 'timed out (exit status 124)', 124, null, 'out of memory' );

		self::assertSame( $timedOut, self::judged( $timedOut ) );
	}

	/**
	 * A crash under the cap says the cap may be why; it is not named out of
	 * memory.
	 */
	public function testACrashSaysTheCapMayBeWhy(): void
	{
		$judged = self::judged( new RenderOutcome( Outcome::KILLED, 'killed by signal 11', null, 11 ) );

		self::assertSame( Outcome::KILLED, $judged->outcome );
		self::assertSame( 'killed by signal 11; under the address-space cap, a crash is often memory running out (vips_prlimit_as_mb)', $judged->reason );
	}

	/**
	 * SIGXFSZ, as `prlimit --fsize` ends a write past it, names the cap.
	 */
	public function testTheFileSizeCapIsNamed(): void
	{
		$killed = new RenderOutcome( Outcome::KILLED, 'killed by signal 25', null, 25, 'out of memory' );

		self::assertSame( 'a write went past the file-size cap (prlimit_fsize_mb): killed by signal 25', self::judged( $killed )->reason );
		self::assertSame(
			'a write went past the file-size cap (the copy\'s size): killed by signal 25',
			RunJudge::judged( $killed, null, self::PHRASES, 'vips_prlimit_as_mb', 'the copy\'s size' )->reason,
		);
	}

	public function testSuccessWithoutTheFileIsNoOutput(): void
	{
		$succeeded = new RenderOutcome( Outcome::SUCCEEDED, 'exit status 0', 0 );

		self::assertSame( Outcome::NO_OUTPUT, self::judged( $succeeded, '/nonexistent/out.png' )->outcome );
		self::assertSame( $succeeded, self::judged( $succeeded, __FILE__ ) );
		// Written to a descriptor: the caller looks at it.
		self::assertSame( $succeeded, self::judged( $succeeded, null ) );
	}


//  static methods

	private static function judged(
		RenderOutcome $outcome,
		?string       $output = __FILE__,
	): RenderOutcome
	{
		return RunJudge::judged( $outcome, $output, self::PHRASES, 'vips_prlimit_as_mb' );
	}
}
