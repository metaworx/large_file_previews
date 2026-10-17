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
				'min_size_all_types',
				'timeout_enabled',
				'timeout_seconds',
				'timeout_kill_after_seconds',
				'failure_retry_minutes',
				'prlimit_enabled',
				'prlimit_as_mb',
				'vips_prlimit_as_mb',
				'prlimit_fsize_mb',
				'tiff_restrip_max_mb',
				'choom_enabled',
				'choom_adjust',
				'scan_native_enabled',
				'scan_coverage_percent',
				'vips_concurrency',
				'preview_format',
				'preview_background',
				'preview_stream',
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
	 * A key removed stays removed: none is declared again.
	 */
	public function testNoObsoleteKeyIsDeclared(): void
	{
		foreach ( ( new ConfigLexicon() )->getAppConfigs() as $entry )
		{
			self::assertNotContains( $entry->getKey(), ConfigLexicon::OBSOLETE );
		}
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

	/**
	 * Eager, since a render reads them; the last failure only the page and
	 * the setup check read, so it is lazy.
	 */
	public function testEveryKeyIsInternalAndEagerButTheLastFailure(): void
	{
		foreach ( ( new ConfigLexicon() )->getAppConfigs() as $entry )
		{
			self::assertSame( IAppConfig::FLAG_INTERNAL, $entry->getFlags(), $entry->getKey() );
			self::assertSame( $entry->getKey() === ConfigLexicon::LAST_FAILURE, $entry->isLazy(), $entry->getKey() );
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
				match ( true )
				{
					is_bool( $spec['default'] )   => ValueType::BOOL,
					is_string( $spec['default'] ) => ValueType::STRING,
					default                       => ValueType::INT,
				},
				$entries[ $key ]->getValueType(),
				$key,
			);

			// A word's default is one of its words.
			if ( is_string( $spec['default'] ) )
			{
				self::assertContains( $spec['default'], $spec['choices'] ?? [], $key );
			}
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

	/**
	 * Installed, the app takes nothing: an administrator enables each type.
	 */
	public function testEveryTypeIsOffByDefault(): void
	{
		$defaults = ConfigLexicon::defaultMimeTypes();

		self::assertSame( SupportedTypes::all(), array_keys( $defaults ) );

		foreach ( $defaults as $mime => $stored )
		{
			self::assertFalse( $stored['enabled'], $mime );
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
