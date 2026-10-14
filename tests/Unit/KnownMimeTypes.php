<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Unit;

use OCP\Files\IMimeTypeDetector;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * An `IMimeTypeDetector` that knows the extensions the tests use, as
 * Nextcloud's mapping gives them; any other is `application/octet-stream`.
 */
trait KnownMimeTypes
{

//  other non-static methods

	protected function mimeDetector(): IMimeTypeDetector&MockObject
	{
		$known = [
			'pdf'  => 'application/pdf',
			'ai'   => 'application/illustrator',
			'tif'  => 'image/tiff',
			'tiff' => 'image/tiff',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'7z'   => 'application/x-7z-compressed',
			'txt'  => 'text/plain',
		];

		$detector = $this->createMock( IMimeTypeDetector::class );
		$detector->method( 'detectPath' )
		         ->willReturnCallback(
			         static fn(
				         string $path,
			         ): string => $known[ strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) ) ] ?? 'application/octet-stream',
		         )
		;

		return $detector;
	}
}
