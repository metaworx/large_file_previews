<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Config;

/**
 * A configuration file as read: the values this app knows, each checked as
 * far as it can be on its own, what it left out, and what the file says of
 * where it came from (ConfigTransfer::read()).
 */
final readonly class Transfer
{

//  constructor

	/**
	 * @param array<string, int|bool|string> $settings   by key, as they would be stored
	 * @param array<string, TypeRule>        $mimetypes  by MIME type
	 * @param array<string, TypeRule>        $extensions by extension
	 * @param array<string, string>          $binaries   paths by program
	 * @param bool|null                      $untrusted  null where the file says nothing
	 * @param string|null                    $scratch    null where the file says nothing
	 * @param string|null                    $version    the app version that wrote the file
	 * @param string|null                    $date       when it was written
	 * @param list<TransferWarning>          $warnings   what was left out
	 */
	public function __construct(
		public array   $settings = [],
		public array   $mimetypes = [],
		public array   $extensions = [],
		public array   $binaries = [],
		public ?bool   $untrusted = null,
		public ?string $scratch = null,
		public ?string $version = null,
		public ?string $date = null,
		public array   $warnings = [],
	)
	{
	}
}
