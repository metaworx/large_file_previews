<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Service;

/**
 * How a run of an external program ended, or why it never started.
 *
 * Every kind but SUCCEEDED is a reason the preview is not made here, and
 * the next provider gets its turn.
 */
enum Outcome: string
{

//  cases

	/**
	 * Exit status 0. Whether it wrote what it should is the caller's to
	 * check: a renderer's warnings on stderr are no failure.
	 */
	case SUCCEEDED = 'succeeded';

	/**
	 * Exit status 0, but no output file.
	 */
	case NO_OUTPUT = 'no_output';

	/**
	 * Exit status 0, but the output is not an image.
	 */
	case INVALID_OUTPUT = 'invalid_output';

	case PROGRAM_MISSING = 'program_missing';

	/**
	 * A limit is switched on and its program is missing: the run fails
	 * closed rather than go ahead without it.
	 */
	case LIMIT_MISSING = 'limit_missing';

	/**
	 * `proc_open()` and its siblings are disabled, by `disable_functions`.
	 */
	case PROC_OPEN_UNAVAILABLE = 'proc_open_unavailable';

	case START_FAILED = 'start_failed';

	/**
	 * timeout(1) stopped it with SIGTERM: exit status 124.
	 */
	case TIMED_OUT = 'timed_out';

	/**
	 * Ended by a signal: timeout(1)'s SIGKILL after its grace period, which
	 * GNU timeout passes on by raising it on itself (another may exit 137),
	 * or a signal from anywhere else, the OOM killer's included.
	 */
	case KILLED = 'killed';

	/**
	 * Any other exit status: the program refused or failed, a password, a
	 * damaged file, or an allocation the address-space cap refused.
	 */
	case FAILED = 'failed';

	/**
	 * The address-space cap refused an allocation, and the program said so,
	 * whatever its exit status: poppler exits 0 and leaves a PNG of a few
	 * bytes.
	 */
	case OUT_OF_MEMORY = 'out_of_memory';


//  getters / setters / is* / has*

	public function isSuccess(): bool
	{
		return $this === self::SUCCEEDED;
	}


//  other non-static methods

	/**
	 * Whether the failure is the file's, and would come again: not a
	 * program or a function missing, nor a start that failed, which the
	 * server's next request may well not repeat.
	 */
	public function concernsTheFile(): bool
	{
		return match ( $this )
		{
			self::NO_OUTPUT,
			self::INVALID_OUTPUT,
			self::TIMED_OUT,
			self::KILLED,
			self::FAILED,
			self::OUT_OF_MEMORY => true,
			default             => false,
		};
	}
}
