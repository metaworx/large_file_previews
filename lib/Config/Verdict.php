<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Config;

/**
 * Whether the app takes a file of a given type and size, and if not, why.
 */
enum Verdict: string
{

//  cases

	case TAKEN = 'taken';

	/**
	 * The MIME type is not one the provider registers for: such a file
	 * never reaches the app.
	 */
	case UNREACHABLE = 'unreachable';

	case DISABLED = 'disabled';

	case BELOW_MIN = 'below_min';

	case ABOVE_MAX = 'above_max';


//  getters / setters / is* / has*

	public function isTaken(): bool
	{
		return $this === self::TAKEN;
	}


//  other non-static methods

	/**
	 * In English, for the log and the commands.
	 */
	public function describe(): string
	{
		return match ( $this )
		{
			self::TAKEN       => 'taken',
			self::UNREACHABLE => 'never reaches this app: the provider does not register for this MIME type',
			self::DISABLED    => 'declined: disabled',
			self::BELOW_MIN   => 'declined: smaller than min_size_mb',
			self::ABOVE_MAX   => 'declined: larger than max_size_mb',
		};
	}
}
