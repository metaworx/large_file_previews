<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Renderer;

/**
 * Why a renderer cannot decode a type on this server: its decoder is not
 * there, it is untrusted and blocked, or libvips cannot block untrusted
 * decoders at all.
 */
final readonly class Unsupported
{

//  constants

	public const MISSING = 'missing';

	public const UNTRUSTED = 'untrusted';

	/**
	 * libvips cannot block its untrusted decoders here, and they are not
	 * allowed: it picks its decoder by the content, so any image type could
	 * reach one.
	 */
	public const UNBLOCKABLE = 'unblockable';

	/** What UNBLOCKABLE means, in English, for the log and `lfp:check`. */
	public const UNBLOCKABLE_TEXT = 'libvips blocks untrusted decoders from 8.13 on, and this one is older or did not say, so no image is rendered unless vips_allow_untrusted is set';


//  constructor

	/**
	 * @param string $decoder the decoder's name, as libvips lists it
	 * @param string $problem self::MISSING, UNTRUSTED or UNBLOCKABLE
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
		return match ( $this->problem )
		{
			self::UNTRUSTED   => sprintf( '%s is an untrusted decoder, and those are blocked unless vips_allow_untrusted is set', $this->decoder ),
			self::UNBLOCKABLE => self::UNBLOCKABLE_TEXT,
			default           => sprintf( 'libvips here has no %s', $this->decoder ),
		};
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
