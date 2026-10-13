<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Service;

/**
 * Where a program was found, and how: by an override in system config, by
 * Nextcloud's binary finder, or not at all.
 */
final readonly class BinaryLocation
{

//  constants

	public const SOURCE_OVERRIDE = 'override';

	public const SOURCE_FINDER = 'finder';


//  constructor

	/**
	 * @param string|null $path    the executable, or null where there is none
	 * @param string      $source  self::SOURCE_OVERRIDE or self::SOURCE_FINDER
	 * @param string|null $problem why an override is not used, in English
	 */
	public function __construct(
		public string  $program,
		public ?string $path,
		public string  $source,
		public ?string $problem = null,
	)
	{
	}


//  getters / setters / is* / has*

	public function isFound(): bool
	{
		return $this->path !== null;
	}
}
