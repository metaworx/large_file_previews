<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Config;

/**
 * One thing an import changes: a setting, a MIME type's or an extension's
 * rule, or a system value, from what is stored to what will be. Null on
 * either side is nothing stored: a setting's default, a type as it ships,
 * no rule for an extension, no system value.
 */
final readonly class TransferChange
{

//  constants

	public const SETTINGS = 'settings';

	public const MIMETYPES = 'mimetypes';

	public const EXTENSIONS = 'extensions';

	/** A system value: `binaries.<program>`, `vips_allow_untrusted` or `scratch_directory`. */
	public const SYSTEM = 'system';


//  constructor

	/**
	 * @param int|bool|string|array<string, int|bool>|null $before
	 * @param int|bool|string|array<string, int|bool>|null $after
	 */
	public function __construct(
		public string                     $part,
		public string                     $name,
		public int|bool|string|array|null $before,
		public int|bool|string|array|null $after,
	)
	{
	}


//  other non-static methods

	/**
	 * @return array{part: string, name: string, before: int|bool|string|array<string, int|bool>|null, after: int|bool|string|array<string, int|bool>|null}
	 */
	public function toArray(): array
	{
		return [
			'part'   => $this->part,
			'name'   => $this->name,
			'before' => $this->before,
			'after'  => $this->after,
		];
	}
}
