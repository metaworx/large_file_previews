<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Config;

/**
 * Where a resolved value came from: the most specific level that set it.
 * A MIME type's shipped rule is a level of its own, below what is stored for
 * the type: an image's minimum, set by nobody, is not the MIME type's.
 */
enum Source: string
{

//  cases

	case DEFAULT = 'default';

	case SHIPPED = 'shipped';

	case MIME = 'mime';

	case EXTENSION = 'ext';
}
