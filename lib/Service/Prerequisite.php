<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Service;

/**
 * One thing the app needs from the server, and whether it has it.
 */
final readonly class Prerequisite
{

//  constants

	public const GROUP_PERMISSION = 'permission';

	public const GROUP_EXTENSION = 'extension';

	public const GROUP_PROGRAM = 'program';

	public const OK = 'ok';

	public const MISSING = 'missing';

	public const DISABLED = 'disabled';

	/**
	 * Missing, and not needed: an optional extension, or a limit switched
	 * off.
	 */
	public const OPTIONAL = 'optional';


//  constructor

	/**
	 * @param string $group  self::GROUP_*
	 * @param string $status self::OK, MISSING, DISABLED or OPTIONAL
	 * @param string $detail a path, a version, or why, in English
	 */
	public function __construct(
		public string $group,
		public string $name,
		public string $status,
		public string $neededFor,
		public string $detail = '',
	)
	{
	}


//  getters / setters / is* / has*

	/**
	 * Whether the app cannot work as configured without it.
	 */
	public function isBlocking(): bool
	{
		return $this->status === self::MISSING || $this->status === self::DISABLED;
	}


//  other non-static methods

	/**
	 * @return array{group: string, name: string, status: string, needed_for: string, detail: string}
	 */
	public function toArray(): array
	{
		return [
			'group'      => $this->group,
			'name'       => $this->name,
			'status'     => $this->status,
			'needed_for' => $this->neededFor,
			'detail'     => $this->detail,
		];
	}
}
