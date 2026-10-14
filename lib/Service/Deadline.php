<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Service;

/**
 * When one render must be done: timeout_seconds from its start, shared by
 * every program it runs - a probe, the renderer, a conversion, a re-striped
 * copy - so that four of them cannot each take the whole of it.
 *
 * Each run is given the time left (RunOptions); a run that would start
 * after it is not started.
 */
final readonly class Deadline
{

//  constructor

	private function __construct(
		private float $until,
	)
	{
	}


//  other non-static methods

	/**
	 * Whole seconds left, rounded up, so that a run started in the last
	 * moment still gets one; 0 once it has passed.
	 */
	public function left(): int
	{
		return max( 0, (int) ceil( $this->until - microtime( true ) ) );
	}


//  static methods

	public static function after( int $seconds ): self
	{
		return new self( microtime( true ) + (float) $seconds );
	}
}
