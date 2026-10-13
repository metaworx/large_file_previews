<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Config;

use InvalidArgumentException;

/**
 * A setting refused: the message in English, for the log and the commands,
 * and the hint saying the same in the reader's language, for the settings
 * page.
 */
class ConfigurationException
    extends
    InvalidArgumentException
{

//  constructor

	public function __construct(
		string                 $message,
		public readonly string $hint,
	)
	{
		parent::__construct( $message );
	}
}
