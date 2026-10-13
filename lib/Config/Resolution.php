<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Config;

/**
 * What the configuration says for one MIME type and extension: each field's
 * value, the level it came from, and the verdict for a size.
 *
 * The provider and the commands read the same object, so what a command
 * shows is what the provider decides.
 */
final readonly class Resolution
{

//  constants

	public const BYTES_PER_MB = 1_048_576;


//  constructor

	/**
	 * @param bool $reachable whether the provider registers for the MIME type
	 */
	public function __construct(
		public ?string $mime,
		public ?string $extension,
		public bool    $reachable,
		public bool    $enabled,
		public Source  $enabledSource,
		public int     $minSizeMb,
		public Source  $minSource,
		public int     $maxSizeMb,
		public Source  $maxSource,
	)
	{
	}


//  other non-static methods

	/**
	 * Whether a file of this many bytes is taken: `min ≤ size ≤ max`, with
	 * a maximum of -1 meaning no limit.
	 */
	public function verdict( int $bytes ): Verdict
	{
		if ( ! $this->reachable )
		{
			return Verdict::UNREACHABLE;
		}

		if ( ! $this->enabled )
		{
			return Verdict::DISABLED;
		}

		if ( $bytes < $this->minSizeMb * self::BYTES_PER_MB )
		{
			return Verdict::BELOW_MIN;
		}

		if ( $this->maxSizeMb !== -1 && $bytes > $this->maxSizeMb * self::BYTES_PER_MB )
		{
			return Verdict::ABOVE_MAX;
		}

		return Verdict::TAKEN;
	}

	/**
	 * @return array{mime: ?string, extension: ?string, reachable: bool, enabled: array{value: bool, source: string}, min_size_mb: array{value: int, source: string}, max_size_mb: array{value: int, source: string}}
	 */
	public function toArray(): array
	{
		return [
			'mime'        => $this->mime,
			'extension'   => $this->extension,
			'reachable'   => $this->reachable,
			'enabled'     => [
				'value'  => $this->enabled,
				'source' => $this->enabledSource->value,
			],
			'min_size_mb' => [
				'value'  => $this->minSizeMb,
				'source' => $this->minSource->value,
			],
			'max_size_mb' => [
				'value'  => $this->maxSizeMb,
				'source' => $this->maxSource->value,
			],
		];
	}
}
