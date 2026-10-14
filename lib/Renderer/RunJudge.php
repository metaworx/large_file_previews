<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Renderer;

use OCA\LargeFilePreviews\Config\ConfigLexicon;
use OCA\LargeFilePreviews\Service\Outcome;
use OCA\LargeFilePreviews\Service\RenderOutcome;

/**
 * How a renderer's run went, beyond its exit status: the one judgement
 * poppler's and libvips' runs share.
 *
 * - A write stopped by the file-size cap (SIGXFSZ) says which cap.
 * - Out of memory where the program said so, whatever its exit status:
 *   poppler exits 0 and leaves a PNG of a few bytes, libvips fails.
 * - A crash (SIGABRT, SIGSEGV) under the address-space cap says the cap may
 *   be why; it is not named out of memory, since a hostile file can cause
 *   one too.
 * - Exit status 0 with no output file is no success.
 *
 * A timeout stays a timeout, whatever was printed before it.
 */
final class RunJudge
{

//  constants

	/** SIGABRT and SIGSEGV: how libvips, libheif and poppler end under the cap. */
	public const CRASH_SIGNALS = [ 6, 11 ];

	/** SIGXFSZ: a write past `prlimit --fsize`. */
	public const FILE_SIZE_SIGNAL = 25;


//  static methods

	/**
	 * @param string|null  $output      the file the run should have written;
	 *                                  null where it writes to a descriptor
	 * @param list<string> $outOfMemory what the program prints when the cap
	 *                                  refuses it memory
	 * @param string       $capKey      the setting of its address-space cap
	 * @param string       $fsizeCap    what its file-size cap is
	 */
	public static function judged(
		RenderOutcome $outcome,
		?string       $output,
		array         $outOfMemory,
		string        $capKey,
		string        $fsizeCap = ConfigLexicon::PRLIMIT_FSIZE_MB,
	): RenderOutcome
	{
		return match ( true )
		{
			$outcome->signal === self::FILE_SIZE_SIGNAL                                            => $outcome->as(
				Outcome::KILLED,
				sprintf( 'a write went past the file-size cap (%s): killed by signal %d', $fsizeCap, self::FILE_SIZE_SIGNAL ),
			),
			$outcome->outcome !== Outcome::TIMED_OUT && self::says( $outcome->stderr, $outOfMemory ) => $outcome->as(
				Outcome::OUT_OF_MEMORY,
				sprintf( 'out of memory under the address-space cap (%s)', $capKey ),
			),
			$outcome->outcome === Outcome::KILLED && in_array( $outcome->signal, self::CRASH_SIGNALS, true ) => $outcome->as(
				Outcome::KILLED,
				sprintf( '%s; under the address-space cap, a crash is often memory running out (%s)', $outcome->reason, $capKey ),
			),
			$outcome->isSuccess() && $output !== null && ! is_file( $output )                       => $outcome->as(
				Outcome::NO_OUTPUT,
				'exit status 0, but no image written',
			),
			default                                                                                 => $outcome,
		};
	}

	/**
	 * @param list<string> $phrases
	 */
	private static function says(
		string $stderr,
		array  $phrases,
	): bool
	{
		foreach ( $phrases as $phrase )
		{
			if ( str_contains( $stderr, $phrase ) )
			{
				return true;
			}
		}

		return false;
	}
}
