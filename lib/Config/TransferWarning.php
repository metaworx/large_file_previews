<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Config;

/**
 * Something in a configuration file this app does not know, and so leaves
 * out: the import goes on with the rest, and says what it left out.
 *
 * Its kind and name are what a reader is told, in their own language on the
 * page and in English by occ (text()).
 */
final readonly class TransferWarning
{

//  constants

	/** A part of the file beside settings, mimetypes, extensions and system. */
	public const PART = 'part';

	/** A setting this app does not have. */
	public const SETTING = 'setting';

	/** A MIME type this app does not handle. */
	public const TYPE = 'type';

	/** A field of a rule beside enabled, min_size_mb and max_size_mb: `<rule>.<field>`. */
	public const FIELD = 'field';

	/** An extension of a type this app does not handle. */
	public const EXTENSION = 'extension';

	/** A value of the file's system part this app does not have. */
	public const SYSTEM = 'system';

	/** A program whose path this app does not run. */
	public const PROGRAM = 'program';


//  constructor

	public function __construct(
		public string $kind,
		public string $name,
	)
	{
	}


//  other non-static methods

	/**
	 * What was left out, in English, for occ and the log.
	 */
	public function text(): string
	{
		return match ( $this->kind )
		{
			self::PART      => sprintf( 'part %s: not one this app reads; left out', $this->name ),
			self::SETTING   => sprintf( 'setting %s: not one this app has; left out', $this->name ),
			self::TYPE      => sprintf( 'MIME type %s: not one this app handles; left out', $this->name ),
			self::FIELD     => sprintf( 'field %s: not one a rule has; left out', $this->name ),
			self::EXTENSION => sprintf( 'extension %s: of a type this app does not handle; left out', $this->name ),
			self::SYSTEM    => sprintf( 'system value %s: not one this app has; left out', $this->name ),
			self::PROGRAM   => sprintf( 'program %s: not one this app runs; left out', $this->name ),
			default         => sprintf( '%s %s: left out', $this->kind, $this->name ),
		};
	}

	/**
	 * @return array{kind: string, name: string}
	 */
	public function toArray(): array
	{
		return [
			'kind' => $this->kind,
			'name' => $this->name,
		];
	}
}
