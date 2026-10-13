<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Unit\Config;

use OCA\LargeFilePreviews\Config\Resolution;
use OCA\LargeFilePreviews\Config\Source;
use OCA\LargeFilePreviews\Config\Verdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ResolutionTest
    extends
    TestCase
{

//  constants

	private const MB = Resolution::BYTES_PER_MB;


//  other non-static methods

	#[DataProvider( 'sizes' )]
	public function testTheSizeWindowIsInclusive(
		int     $min,
		int     $max,
		int     $bytes,
		Verdict $expected,
	): void
	{
		self::assertSame( $expected, self::resolution( true, $min, $max )->verdict( $bytes ) );
	}

	public function testDisabledComesBeforeTheSize(): void
	{
		self::assertSame( Verdict::DISABLED, self::resolution( false, 0, -1 )->verdict( 0 ) );
	}

	public function testUnreachableComesFirst(): void
	{
		$resolution = new Resolution(
			'text/plain',
			null,
			false,
			true,
			Source::DEFAULT,
			0,
			Source::DEFAULT,
			-1,
			Source::DEFAULT,
		);

		self::assertSame( Verdict::UNREACHABLE, $resolution->verdict( 10 ) );
	}

	public function testToArrayNamesEachSource(): void
	{
		$array = self::resolution( true, 50, -1 )->toArray();

		self::assertSame( [ 'value' => true, 'source' => 'mime' ], $array['enabled'] );
		self::assertSame( [ 'value' => 50, 'source' => 'ext' ], $array['min_size_mb'] );
		self::assertSame( [ 'value' => -1, 'source' => 'default' ], $array['max_size_mb'] );
	}


//  static methods

	/**
	 * @return array<string, array{int, int, int, Verdict}>
	 */
	public static function sizes(): array
	{
		return [
			'one byte below min'   => [ 50, -1, 50 * self::MB - 1, Verdict::BELOW_MIN ],
			'exactly min'          => [ 50, -1, 50 * self::MB, Verdict::TAKEN ],
			'exactly max'          => [ 0, 100, 100 * self::MB, Verdict::TAKEN ],
			'one byte above max'   => [ 0, 100, 100 * self::MB + 1, Verdict::ABOVE_MAX ],
			'no limit'             => [ 0, -1, PHP_INT_MAX, Verdict::TAKEN ],
			'empty file, min zero' => [ 0, -1, 0, Verdict::TAKEN ],
			'min above max'        => [ 10, 5, 7 * self::MB, Verdict::BELOW_MIN ],
		];
	}

	private static function resolution(
		bool $enabled,
		int  $min,
		int  $max,
	): Resolution
	{
		return new Resolution(
			'image/tiff',
			'tif',
			true,
			$enabled,
			Source::MIME,
			$min,
			Source::EXTENSION,
			$max,
			Source::DEFAULT,
		);
	}
}
