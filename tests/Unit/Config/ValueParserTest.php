<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Unit\Config;

use OCA\LargeFilePreviews\Config\ValueParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ValueParserTest
    extends
    TestCase
{

//  other non-static methods

	#[DataProvider( 'bools' )]
	public function testBool(
		string $text,
		?bool  $expected,
	): void
	{
		self::assertSame( $expected, ValueParser::bool( $text ) );
	}

	#[DataProvider( 'ints' )]
	public function testInt(
		string $text,
		?int   $expected,
	): void
	{
		self::assertSame( $expected, ValueParser::int( $text ) );
	}

	#[DataProvider( 'sizes' )]
	public function testSize(
		string $text,
		?int   $expected,
	): void
	{
		self::assertSame( $expected, ValueParser::size( $text ) );
	}


//  static methods

	/**
	 * @return array<string, array{string, ?bool}>
	 */
	public static function bools(): array
	{
		return [
			'yes'      => [ 'yes', true ],
			'TRUE'     => [ 'TRUE', true ],
			'on'       => [ ' on ', true ],
			'1'        => [ '1', true ],
			'no'       => [ 'no', false ],
			'off'      => [ 'off', false ],
			'0'        => [ '0', false ],
			'maybe'    => [ 'maybe', null ],
			'empty'    => [ '', null ],
		];
	}

	/**
	 * @return array<string, array{string, ?int}>
	 */
	public static function ints(): array
	{
		return [
			'zero'     => [ '0', 0 ],
			'negative' => [ '-1', -1 ],
			'spaces'   => [ ' 42 ', 42 ],
			'decimal'  => [ '1.5', null ],
			'unit'     => [ '5mb', null ],
			'text'     => [ 'ten', null ],
		];
	}

	/**
	 * @return array<string, array{string, ?int}>
	 */
	public static function sizes(): array
	{
		return [
			'bytes'      => [ '1234', 1234 ],
			'b'          => [ '10b', 10 ],
			'kb'         => [ '2kb', 2048 ],
			'mb'         => [ '60mb', 62_914_560 ],
			'MB, spaced' => [ '60 MB', 62_914_560 ],
			'm'          => [ '1m', 1_048_576 ],
			'gb'         => [ '2gb', 2_147_483_648 ],
			'tb'         => [ '1tb', null ],
			'negative'   => [ '-5mb', null ],
			'decimal'    => [ '1.5gb', null ],
		];
	}
}
