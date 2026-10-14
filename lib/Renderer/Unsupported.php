<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Renderer;

/**
 * Why a renderer cannot decode a type on this server: its decoder is not
 * there, or it is untrusted and blocked.
 */
final readonly class Unsupported
{

//  constants

	public const MISSING = 'missing';

	public const UNTRUSTED = 'untrusted';


//  constructor

	/**
	 * @param string $decoder the decoder's name, as libvips lists it
	 * @param string $problem self::MISSING or self::UNTRUSTED
	 */
	public function __construct(
		public string $decoder,
		public string $problem,
	)
	{
	}


//  other non-static methods

	/**
	 * In English, for the log.
	 */
	public function describe(): string
	{
		return $this->problem === self::UNTRUSTED
			? sprintf( '%s is an untrusted decoder, and those are blocked (vips_block_untrusted)', $this->decoder )
			: sprintf( 'libvips here has no %s', $this->decoder );
	}

	/**
	 * @return array{decoder: string, problem: string}
	 */
	public function toArray(): array
	{
		return [
			'decoder' => $this->decoder,
			'problem' => $this->problem,
		];
	}
}
