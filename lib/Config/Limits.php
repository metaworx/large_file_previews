<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Config;

/**
 * The limits each render runs under, each switchable, with its value.
 */
final readonly class Limits
{

//  constructor

	public function __construct(
		public bool $timeoutEnabled,
		public int  $timeoutSeconds,
		public int  $timeoutKillAfterSeconds,
		public bool $prlimitEnabled,
		public int  $prlimitAsMb,
		public bool $choomEnabled,
		public int  $choomAdjust,
	)
	{
	}
}
