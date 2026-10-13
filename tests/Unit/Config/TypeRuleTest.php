<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Unit\Config;

use OCA\LargeFilePreviews\Config\TypeRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TypeRuleTest
    extends
    TestCase
{

//  other non-static methods

	public function testFromArrayReadsEveryField(): void
	{
		$rule = TypeRule::fromArray( [ 'enabled' => true, 'min_size_mb' => 10, 'max_size_mb' => 20 ] );

		self::assertEquals( new TypeRule( true, 10, 20 ), $rule );
	}

	public function testFromArrayLeavesAbsentFieldsUnset(): void
	{
		self::assertEquals( new TypeRule( null, 5, null ), TypeRule::fromArray( [ 'min_size_mb' => 5 ] ) );
		self::assertEquals( new TypeRule(), TypeRule::fromArray( [] ) );
	}

	#[DataProvider( 'invalidRules' )]
	public function testFromArrayNamesWhatIsWrong(
		mixed  $stored,
		string $reason,
	): void
	{
		self::assertSame( $reason, TypeRule::fromArray( $stored ) );
	}

	public function testMaxMinusOneMeansNoLimitAndAllowsAnyMin(): void
	{
		self::assertNull( ( new TypeRule( null, 500, -1 ) )->problem() );
	}

	public function testMinEqualToMaxIsValid(): void
	{
		self::assertNull( ( new TypeRule( null, 7, 7 ) )->problem() );
	}

	public function testWithLaysSetFieldsOver(): void
	{
		$rule = ( new TypeRule( false, 50, null ) )->with( new TypeRule( true, null, 100 ) );

		self::assertEquals( new TypeRule( true, 50, 100 ), $rule );
	}

	public function testWithoutClearsNamedFields(): void
	{
		$rule = ( new TypeRule( true, 50, 100 ) )->without( [ TypeRule::FIELD_MIN, TypeRule::FIELD_ENABLED ] );

		self::assertEquals( new TypeRule( null, null, 100 ), $rule );
	}

	public function testToArrayKeepsOnlySetFields(): void
	{
		self::assertSame( [ 'enabled' => false, 'max_size_mb' => -1 ], ( new TypeRule( false, null, -1 ) )->toArray() );
		self::assertTrue( ( new TypeRule() )->isEmpty() );
	}


//  static methods

	/**
	 * @return array<string, array{mixed, string}>
	 */
	public static function invalidRules(): array
	{
		return [
			'not an object'      => [ 'yes', 'not an object' ],
			'unknown field'      => [ [ 'enabled' => true, 'colour' => 'red' ], 'unknown field colour' ],
			'enabled as text'    => [ [ 'enabled' => 'yes' ], 'enabled is not true or false' ],
			'min as text'        => [ [ 'min_size_mb' => '5' ], 'min_size_mb is not a whole number' ],
			'max as float'       => [ [ 'max_size_mb' => 1.5 ], 'max_size_mb is not a whole number' ],
			'negative min'       => [ [ 'min_size_mb' => -1 ], 'min_size_mb is below 0' ],
			'max below -1'       => [ [ 'max_size_mb' => -2 ], 'max_size_mb is below -1' ],
			'min above max'      => [ [ 'min_size_mb' => 10, 'max_size_mb' => 5 ], 'min_size_mb is above max_size_mb' ],
		];
	}
}
