<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Unit\Config;

use OCA\LargeFilePreviews\Config\SupportedTypes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SupportedTypesTest
    extends
    TestCase
{

//  other non-static methods

	/**
	 * Providers are tried longest regex first; core's longest, Imaginary's,
	 * is 92 characters, camerarawpreviews' 49.
	 */
	public function testRegexIsLongerThanAnyCompetitor(): void
	{
		self::assertGreaterThan( 92, strlen( SupportedTypes::MIME_REGEX ) );
	}

	public function testEveryListedTypeMatches(): void
	{
		foreach ( SupportedTypes::all() as $mime )
		{
			self::assertTrue( SupportedTypes::matches( $mime ), $mime );
		}
	}

	public function testTheListsDoNotOverlap(): void
	{
		self::assertSame( [], array_intersect( SupportedTypes::DOCUMENT_TYPES, SupportedTypes::IMAGE_TYPES ) );
		self::assertSame( SupportedTypes::all(), array_values( array_unique( SupportedTypes::all() ) ) );
	}

	#[DataProvider( 'unmatchedTypes' )]
	public function testTypesLeftOutDoNotMatch( string $mime ): void
	{
		self::assertFalse( SupportedTypes::matches( $mime ) );
	}

	/**
	 * Every type the regex's alternation spells out is listed: a type that
	 * matches but is not listed would reach the provider with no rule.
	 */
	public function testNoUnlistedTypeMatches(): void
	{
		$candidates = [
			'application/pdf',
			'application/illustrator',
			'application/postscript',
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
			'image/x-portable-anymap',
			'image/svg+xml',
		];

		foreach ( $candidates as $mime )
		{
			self::assertSame( in_array( $mime, SupportedTypes::all(), true ), SupportedTypes::matches( $mime ), $mime );
		}
	}


//  static methods

	/**
	 * @return array<string, array{string}>
	 */
	public static function unmatchedTypes(): array
	{
		return [
			'svg: small files, and another attack surface' => [ 'image/svg+xml' ],
			'camera raw'                                   => [ 'image/x-dcraw' ],
			'text'                                         => [ 'text/plain' ],
			'a prefix only'                                => [ 'application/pdfx' ],
			'a type inside another'                        => [ 'xapplication/pdf' ],
			'video'                                        => [ 'video/mp4' ],
		];
	}
}
