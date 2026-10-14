<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Config;

/**
 * One change to a stored rule: fields cleared, then fields set.
 * ConfigurationService::apply() takes them, so that a page's change and an
 * occ command's are checked alike, and all together.
 */
final readonly class RuleChange
{

//  constructor

	/**
	 * @param TypeRule     $set   the fields set, the others left as they are
	 * @param list<string> $clear the fields cleared (TypeRule::FIELDS)
	 */
	public function __construct(
		public TypeRule $set = new TypeRule(),
		public array    $clear = [],
	)
	{
	}
}
