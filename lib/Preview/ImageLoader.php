<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Preview;

use OCP\IImage;
use OCP\Image;

/**
 * Loads a rendered preview, a PNG, a JPEG or a WebP, as Nextcloud's image.
 *
 * Its own class so that the provider can be tested without a server:
 * OCP\Image extends a server class.
 */
class ImageLoader
{

//  other non-static methods

	/**
	 * The image, or null where the file is not one.
	 */
	public function load( string $path ): ?IImage
	{
		$data = @file_get_contents( $path );

		if ( ! is_string( $data ) || $data === '' )
		{
			return null;
		}

		$image = self::create();
		$image->loadFromData( $data );

		return $image->valid()
			? $image
			: null;
	}


//  static methods

	private static function create(): IImage
	{
		return new Image();
	}
}
