<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Config;

/**
 * Every MIME type the app may ever take, and the regex it registers its
 * preview provider with.
 *
 * Nextcloud tries providers in the order of their regex's length, longest
 * first (PreviewManager::getProviders(), internal behaviour), so the regex
 * spells out every type rather than using a short pattern: it must stay
 * longer than any other provider's that matches the same types. Core's
 * longest, Imaginary's, is 92 characters. A type in the regex reaches the
 * app's provider; whether the provider takes it is configuration.
 */
final class SupportedTypes
{

//  constants

	public const MIME_REGEX
		 = '/^(?:application\/(?:pdf|illustrator)|image\/(?:tiff|jpeg|png|webp|gif|heic|heif|avif|jp2|jxl|bmp|x-portable-(?:bitmap|graymap|pixmap)))$/';

	/**
	 * Rendered by pdftoppm.
	 */
	public const DOCUMENT_TYPES
		 = [
			'application/pdf',
			'application/illustrator',
		];

	/**
	 * The program the image types are planned for, named where one of them
	 * has no renderer yet.
	 */
	public const PLANNED_IMAGE_RENDERER = 'libvips';

	/**
	 * Not rendered yet: known so that they can be configured, and switched
	 * off until an image renderer ships.
	 */
	public const IMAGE_TYPES
		 = [
			'image/tiff',
			'image/jpeg',
			'image/png',
			'image/webp',
			'image/gif',
			'image/heic',
			'image/heif',
			'image/avif',
			'image/jp2',
			'image/jxl',
			'image/bmp',
			'image/x-portable-bitmap',
			'image/x-portable-graymap',
			'image/x-portable-pixmap',
		];


//  static methods

	/**
	 * @return list<string>
	 */
	public static function all(): array
	{
		return [ ...self::DOCUMENT_TYPES, ...self::IMAGE_TYPES ];
	}

	public static function matches( string $mime ): bool
	{
		return preg_match( self::MIME_REGEX, $mime ) === 1;
	}
}
