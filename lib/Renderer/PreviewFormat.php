<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Renderer;

/**
 * The types a preview is written in. Nextcloud stores a preview in the type
 * of the image it is given, so this is the type it keeps.
 */
enum PreviewFormat: string
{

//  cases

	case PNG  = 'png';
	case JPEG = 'jpeg';
	case WEBP = 'webp';


//  constants

	/**
	 * The quality a JPEG or WebP preview is written at, by either renderer,
	 * before Nextcloud re-encodes it at its own (preview jpeg_quality,
	 * webp_quality).
	 */
	public const QUALITY = 95;


//  other non-static methods

	public function extension(): string
	{
		return match ( $this )
		{
			self::PNG  => 'png',
			self::JPEG => 'jpg',
			self::WEBP => 'webp',
		};
	}


//  static methods

	/**
	 * The format a path's extension names, or null for any other.
	 */
	public static function ofPath( string $path ): ?self
	{
		foreach ( self::cases() as $format )
		{
			if ( str_ends_with( $path, '.' . $format->extension() ) )
			{
				return $format;
			}
		}

		return null;
	}
}
