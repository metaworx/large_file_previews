<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Config;

/**
 * Where a resolved value came from: the most specific level that set it.
 */
enum Source: string
{

//  cases

	case DEFAULT = 'default';

	case MIME = 'mime';

	case EXTENSION = 'ext';
}
