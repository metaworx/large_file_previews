<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Unit\Config;

use OCA\LargeFilePreviews\Config\ConfigLexicon;
use OCA\LargeFilePreviews\Config\SupportedTypes;
use OCA\LargeFilePreviews\Config\TypeRule;
use OCP\Config\Lexicon\Entry;
use OCP\Config\Lexicon\Strictness;
use OCP\Config\ValueType;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

class ConfigLexiconTest
    extends
    TestCase
{

//  other non-static methods

	public function testStrictnessIsWarning(): void
	{
		self::assertSame( Strictness::WARNING, ( new ConfigLexicon() )->getStrictness() );
	}

	/**
	 * The keys are pinned: one added, renamed or dropped is a decision, and
	 * this test is where it shows.
	 */
	public function testTheDeclaredKeys(): void
	{
		self::assertSame(
			[
				'min_size_mb',
				'max_size_mb',
				'timeout_enabled',
				'timeout_seconds',
				'timeout_kill_after_seconds',
				'prlimit_enabled',
				'prlimit_as_mb',
				'vips_prlimit_as_mb',
				'choom_enabled',
				'choom_adjust',
				'scan_native_enabled',
				'scan_coverage_percent',
				'mimetypes',
				'extensions',
				'last_failure',
			],
			array_map(
				static fn(
					Entry $entry,
				): string => $entry->getKey(),
				( new ConfigLexicon() )->getAppConfigs(),
			),
		);
	}

	/**
	 * A path is code execution as the web server's user, and app config is
	 * writable over the provisioning API.
	 */
	public function testNoKeyNamesAPath(): void
	{
		foreach ( ( new ConfigLexicon() )->getAppConfigs() as $entry )
		{
			self::assertDoesNotMatchRegularExpression( '/path|binary|bin_|exec/', $entry->getKey() );
		}
	}

	public function testEveryKeyIsInternalAndEager(): void
	{
		foreach ( ( new ConfigLexicon() )->getAppConfigs() as $entry )
		{
			self::assertSame( IAppConfig::FLAG_INTERNAL, $entry->getFlags(), $entry->getKey() );
			self::assertFalse( $entry->isLazy(), $entry->getKey() );
		}
	}

	public function testScalarTypesFollowTheirDefaults(): void
	{
		$entries = [];

		foreach ( ( new ConfigLexicon() )->getAppConfigs() as $entry )
		{
			$entries[ $entry->getKey() ] = $entry;
		}

		foreach ( ConfigLexicon::SCALARS as $key => $spec )
		{
			self::assertSame(
				is_bool( $spec['default'] ) ? ValueType::BOOL : ValueType::INT,
				$entries[ $key ]->getValueType(),
				$key,
			);
		}

		self::assertSame( ValueType::ARRAY, $entries[ ConfigLexicon::MIMETYPES ]->getValueType() );
		self::assertSame( ValueType::ARRAY, $entries[ ConfigLexicon::EXTENSIONS ]->getValueType() );
	}

	public function testEveryDefaultLiesInItsRange(): void
	{
		foreach ( ConfigLexicon::SCALARS as $key => $spec )
		{
			if ( ! isset( $spec['min'] ) )
			{
				continue;
			}

			self::assertGreaterThanOrEqual( $spec['min'], $spec['default'], $key );

			if ( ( $spec['max'] ?? null ) !== null )
			{
				self::assertLessThanOrEqual( $spec['max'], $spec['default'], $key );
			}
		}
	}

	public function testDefaultMimeTypesCoverEveryTypeAndAreValid(): void
	{
		$defaults = ConfigLexicon::defaultMimeTypes();

		self::assertSame( SupportedTypes::all(), array_keys( $defaults ) );

		foreach ( $defaults as $mime => $stored )
		{
			self::assertInstanceOf( TypeRule::class, TypeRule::fromArray( $stored ), $mime );
		}
	}

	public function testOnlyDocumentsAreOnByDefault(): void
	{
		foreach ( ConfigLexicon::defaultMimeTypes() as $mime => $stored )
		{
			self::assertSame( in_array( $mime, SupportedTypes::DOCUMENT_TYPES, true ), $stored['enabled'], $mime );
		}
	}

	public function testImagesStartAtFiftyMegabytes(): void
	{
		foreach ( SupportedTypes::IMAGE_TYPES as $mime )
		{
			self::assertSame( 50, ConfigLexicon::defaultMimeTypes()[ $mime ]['min_size_mb'] ?? null, $mime );
		}
	}
}
