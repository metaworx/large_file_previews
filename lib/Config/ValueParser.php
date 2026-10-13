<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Config;

/**
 * Values as a person types them on the command line; null where a text is
 * not one.
 */
final class ValueParser
{

//  constants

	private const UNITS
		 = [
			''   => 1,
			'b'  => 1,
			'k'  => 1024,
			'kb' => 1024,
			'm'  => 1_048_576,
			'mb' => 1_048_576,
			'g'  => 1_073_741_824,
			'gb' => 1_073_741_824,
		];


//  static methods

	/**
	 * `true`/`false`, `yes`/`no`, `on`/`off` or `1`/`0`, in any case.
	 */
	public static function bool( string $text ): ?bool
	{
		return match ( strtolower( trim( $text ) ) )
		{
			'1', 'true', 'yes', 'on'  => true,
			'0', 'false', 'no', 'off' => false,
			default                   => null,
		};
	}

	/**
	 * Digits, with an optional minus.
	 */
	public static function int( string $text ): ?int
	{
		$text = trim( $text );

		return preg_match( '/^-?\d{1,18}$/', $text ) === 1
			? (int) $text
			: null;
	}

	/**
	 * A size in bytes: digits, optionally with a unit, `kb`, `mb` or `gb`
	 * (or `k`, `m`, `g`), each a power of 1024 as Nextcloud counts them.
	 */
	public static function size( string $text ): ?int
	{
		if ( preg_match( '/^(\d{1,15})\s*([a-z]*)$/', strtolower( trim( $text ) ), $match ) !== 1 )
		{
			return null;
		}

		$factor = self::UNITS[ $match[2] ] ?? null;

		return $factor === null
			? null
			: (int) $match[1] * $factor;
	}
}
