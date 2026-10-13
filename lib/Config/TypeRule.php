<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Config;

/**
 * What one MIME type or one extension says: each field set, or null to
 * leave it to the level below.
 *
 * Sizes are in MB; a `maxSizeMb` of -1 means no limit.
 */
final readonly class TypeRule
{

//  constants

	public const FIELD_ENABLED = 'enabled';

	public const FIELD_MIN = 'min_size_mb';

	public const FIELD_MAX = 'max_size_mb';

	public const FIELDS
		 = [
			self::FIELD_ENABLED,
			self::FIELD_MIN,
			self::FIELD_MAX,
		];


//  constructor

	public function __construct(
		public ?bool $enabled = null,
		public ?int  $minSizeMb = null,
		public ?int  $maxSizeMb = null,
	)
	{
	}


//  getters / setters / is* / has*

	public function isEmpty(): bool
	{
		return $this->enabled === null && $this->minSizeMb === null && $this->maxSizeMb === null;
	}


//  other non-static methods

	/**
	 * This rule with the fields of another laid over it, where they are set.
	 */
	public function with( self $other ): self
	{
		return new self(
			$other->enabled ?? $this->enabled,
			$other->minSizeMb ?? $this->minSizeMb,
			$other->maxSizeMb ?? $this->maxSizeMb,
		);
	}

	/**
	 * This rule without the named fields.
	 *
	 * @param list<string> $fields
	 */
	public function without( array $fields ): self
	{
		return new self(
			in_array( self::FIELD_ENABLED, $fields, true ) ? null : $this->enabled,
			in_array( self::FIELD_MIN, $fields, true ) ? null : $this->minSizeMb,
			in_array( self::FIELD_MAX, $fields, true ) ? null : $this->maxSizeMb,
		);
	}

	/**
	 * As stored: only the fields that are set.
	 *
	 * @return array{enabled?: bool, min_size_mb?: int, max_size_mb?: int}
	 */
	public function toArray(): array
	{
		return array_filter(
			[
				self::FIELD_ENABLED => $this->enabled,
				self::FIELD_MIN     => $this->minSizeMb,
				self::FIELD_MAX     => $this->maxSizeMb,
			],
			static fn(
				mixed $value,
			): bool => $value !== null,
		);
	}


//  static methods

	/**
	 * A rule from what is stored, or the reason it is not one.
	 *
	 * The reason is English, for the log and the commands; the service
	 * that writes rules refuses the same mistakes before they are stored.
	 */
	public static function fromArray( mixed $stored ): self|string
	{
		if ( ! is_array( $stored ) )
		{
			return 'not an object';
		}

		$unknown = array_diff( array_keys( $stored ), self::FIELDS );

		if ( $unknown !== [] )
		{
			return sprintf( 'unknown field %s', implode( ', ', $unknown ) );
		}

		$enabled = $stored[ self::FIELD_ENABLED ] ?? null;
		$min     = $stored[ self::FIELD_MIN ] ?? null;
		$max     = $stored[ self::FIELD_MAX ] ?? null;

		if ( $enabled !== null && ! is_bool( $enabled ) )
		{
			return 'enabled is not true or false';
		}

		if ( $min !== null && ! is_int( $min ) )
		{
			return 'min_size_mb is not a whole number';
		}

		if ( $max !== null && ! is_int( $max ) )
		{
			return 'max_size_mb is not a whole number';
		}

		$rule    = new self( $enabled, $min, $max );
		$problem = $rule->problem();

		return $problem ?? $rule;
	}

	/**
	 * What is wrong with the sizes this rule sets on their own, or null.
	 *
	 * A minimum above a maximum set at another level is a resolution's
	 * concern, not the rule's.
	 */
	public function problem(): ?string
	{
		if ( $this->minSizeMb !== null && $this->minSizeMb < 0 )
		{
			return 'min_size_mb is below 0';
		}

		if ( $this->maxSizeMb !== null && $this->maxSizeMb < -1 )
		{
			return 'max_size_mb is below -1';
		}

		if ( $this->minSizeMb !== null
			&& $this->maxSizeMb !== null
			&& $this->maxSizeMb !== -1
			&& $this->minSizeMb > $this->maxSizeMb )
		{
			return 'min_size_mb is above max_size_mb';
		}

		return null;
	}
}
