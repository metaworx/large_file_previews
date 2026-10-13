<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Unit\Service;

use OCA\LargeFilePreviews\Config\ConfigLexicon;
use OCA\LargeFilePreviews\Config\ConfigurationException;
use OCA\LargeFilePreviews\Config\TypeRule;
use OCA\LargeFilePreviews\Service\ConfigurationService;
use OCA\LargeFilePreviews\Tests\Unit\EnglishL10n;
use OCA\LargeFilePreviews\Tests\Unit\MemoryAppConfig;
use PHPUnit\Framework\TestCase;

class ConfigurationServiceTest
    extends
    TestCase
{
	use EnglishL10n;
	use MemoryAppConfig;


//  other non-static methods

	public function testDefaultsWhenNothingIsStored(): void
	{
		$service = $this->service();
		$limits  = $service->limits();

		self::assertSame( 0, $service->minSizeMb() );
		self::assertSame( -1, $service->maxSizeMb() );
		self::assertTrue( $limits->timeoutEnabled );
		self::assertSame( 30, $limits->timeoutSeconds );
		self::assertSame( 5, $limits->timeoutKillAfterSeconds );
		self::assertSame( 2048, $limits->prlimitAsMb );
		self::assertSame( 1000, $limits->choomAdjust );
		self::assertEquals( new TypeRule( true ), $service->mimeRules()['application/pdf'] );
		self::assertEquals( new TypeRule( false, 50 ), $service->mimeRules()['image/tiff'] );
		self::assertSame( [], $service->extensionRules() );
		self::assertSame( [], $service->problems() );
	}

	/**
	 * `occ config:app:set` writes past the service; what it wrote is held
	 * to the range when read.
	 */
	public function testAStoredNumberOutsideItsRangeIsHeldToIt(): void
	{
		$this->appValues = [
			ConfigLexicon::TIMEOUT_SECONDS => 5000,
			ConfigLexicon::CHOOM_ADJUST    => -500,
			ConfigLexicon::MIN_SIZE_MB     => -3,
		];

		$service = $this->service();

		self::assertSame( 600, $service->limits()->timeoutSeconds );
		self::assertSame( 0, $service->limits()->choomAdjust );
		self::assertSame( 0, $service->minSizeMb() );
	}

	public function testSetScalarParsesWhatOccPasses(): void
	{
		$service = $this->service();

		$service->setScalar( ConfigLexicon::TIMEOUT_SECONDS, '60' );
		$service->setScalar( ConfigLexicon::PRLIMIT_ENABLED, 'no' );

		self::assertSame( 60, $this->appValues[ ConfigLexicon::TIMEOUT_SECONDS ] );
		self::assertFalse( $this->appValues[ ConfigLexicon::PRLIMIT_ENABLED ] );
	}

	public function testSetScalarRefusesAValueOutOfRange(): void
	{
		$service = $this->service( true );

		try
		{
			$service->setScalar( ConfigLexicon::TIMEOUT_SECONDS, 601 );
			self::fail( 'No refusal' );
		}
		catch ( ConfigurationException $e )
		{
			self::assertSame( 'timeout_seconds must be between 1 and 600.', $e->getMessage() );
			self::assertSame( '«timeout_seconds must be between 1 and 600.»', $e->hint );
		}

		self::assertArrayNotHasKey( ConfigLexicon::TIMEOUT_SECONDS, $this->appValues );
	}

	public function testSetScalarRefusesAnUnboundedMinimum(): void
	{
		$this->expectExceptionMessage( 'max_size_mb must be at least -1.' );

		$this->service()->setScalar( ConfigLexicon::MAX_SIZE_MB, -2 );
	}

	public function testSetScalarRefusesWhatIsNotANumberOrSwitch(): void
	{
		$service = $this->service();

		foreach ( [ [ ConfigLexicon::TIMEOUT_SECONDS, 'ten' ], [ ConfigLexicon::CHOOM_ENABLED, 'maybe' ] ] as [ $key, $value ] )
		{
			try
			{
				$service->setScalar( $key, $value );
				self::fail( 'No refusal for ' . $key );
			}
			catch ( ConfigurationException )
			{
			}
		}

		self::assertSame( [], $this->appValues );
	}

	public function testUnknownKeysAreRefused(): void
	{
		$this->expectException( ConfigurationException::class );

		$this->service()->setScalar( 'pdftoppm_path', '/tmp/evil' );
	}

	public function testMimeTypesAreNotScalars(): void
	{
		$this->expectException( ConfigurationException::class );

		$this->service()->setScalar( ConfigLexicon::MIMETYPES, '{}' );
	}

	public function testUnsetScalarReturnsToTheDefault(): void
	{
		$this->appValues = [ ConfigLexicon::TIMEOUT_SECONDS => 60 ];
		$service         = $this->service();

		$service->unsetScalar( ConfigLexicon::TIMEOUT_SECONDS );

		self::assertSame( 30, $service->limits()->timeoutSeconds );
		self::assertFalse( $service->isStored( ConfigLexicon::TIMEOUT_SECONDS ) );
	}

	public function testSetMimeRuleChangesOnlyTheFieldsGiven(): void
	{
		$service = $this->service();

		$rule = $service->setMimeRule( 'IMAGE/TIFF', new TypeRule( true ) );

		self::assertEquals( new TypeRule( true, 50 ), $rule );
		self::assertSame( [ 'image/tiff' => [ 'enabled' => true, 'min_size_mb' => 50 ] ], $this->appValues[ ConfigLexicon::MIMETYPES ] );
		self::assertEquals( $rule, $service->mimeRules()['image/tiff'] );
	}

	public function testSetMimeRuleRefusesATypeTheProviderDoesNotRegisterFor(): void
	{
		$this->expectExceptionMessage( 'text/plain is not a MIME type this app handles' );

		$this->service()->setMimeRule( 'text/plain', new TypeRule( true ) );
	}

	public function testSetMimeRuleRefusesMinAboveMaxAcrossOldAndNewFields(): void
	{
		$service = $this->service( true );

		try
		{
			// image/tiff already has a minimum of 50.
			$service->setMimeRule( 'image/tiff', new TypeRule( null, null, 10 ) );
			self::fail( 'No refusal' );
		}
		catch ( ConfigurationException $e )
		{
			self::assertSame( 'min_size_mb is above max_size_mb.', $e->getMessage() );
			self::assertSame( '«The minimum size cannot be above the maximum size.»', $e->hint );
		}

		self::assertArrayNotHasKey( ConfigLexicon::MIMETYPES, $this->appValues );
	}

	public function testUnsetMimeRuleReturnsItToItsShippedDefault(): void
	{
		$service = $this->service();
		$service->setMimeRule( 'image/tiff', new TypeRule( true, 100 ) );

		$service->unsetMimeRule( 'image/tiff' );

		self::assertEquals( new TypeRule( false, 50 ), $service->mimeRules()['image/tiff'] );
		self::assertArrayNotHasKey( ConfigLexicon::MIMETYPES, $this->appValues );
	}

	public function testUnsetMimeFieldLeavesItToTheAppDefault(): void
	{
		$service = $this->service();

		$service->unsetMimeRule( 'image/tiff', [ TypeRule::FIELD_MIN ] );

		self::assertEquals( new TypeRule( false ), $service->mimeRules()['image/tiff'] );
	}

	public function testExtensionsAreNormalised(): void
	{
		$service = $this->service();

		$service->setExtensionRule( '.TIF', new TypeRule( null, 200 ) );

		self::assertSame( [ 'tif' => [ 'min_size_mb' => 200 ] ], $this->appValues[ ConfigLexicon::EXTENSIONS ] );
	}

	public function testAnInvalidExtensionIsRefused(): void
	{
		$this->expectExceptionMessage( '"../x" is not a file extension.' );

		$this->service()->setExtensionRule( '../x', new TypeRule( true ) );
	}

	public function testUnsetExtensionRuleRemovesItAndTheKeyWithTheLast(): void
	{
		$service = $this->service();
		$service->setExtensionRule( 'tif', new TypeRule( true, 200 ) );

		$service->unsetExtensionRule( 'tif', [ TypeRule::FIELD_MIN ] );
		self::assertSame( [ 'tif' => [ 'enabled' => true ] ], $this->appValues[ ConfigLexicon::EXTENSIONS ] );

		$service->unsetExtensionRule( 'tif' );
		self::assertArrayNotHasKey( ConfigLexicon::EXTENSIONS, $this->appValues );
	}

	public function testUnknownFieldsAreRefused(): void
	{
		$this->expectExceptionMessage( 'Unknown field colour' );

		$this->service()->unsetExtensionRule( 'tif', [ 'colour' ] );
	}

	/**
	 * What a raw write stored wrongly is left out, and said.
	 */
	public function testInvalidStoredRulesAreReportedAndIgnored(): void
	{
		$this->appValues = [
			ConfigLexicon::MIMETYPES  => [
				'image/tiff' => [ 'enabled' => 'yes' ],
				'text/plain' => [ 'enabled' => true ],
				'image/png'  => [ 'enabled' => true ],
			],
			ConfigLexicon::EXTENSIONS => [
				'TIF' => [ 'enabled' => true ],
				'jpg' => [ 'min_size_mb' => 10, 'max_size_mb' => 5 ],
				'png' => [ 'max_size_mb' => 300 ],
			],
		];

		$service = $this->service();

		self::assertEquals( new TypeRule( false, 50 ), $service->mimeRules()['image/tiff'] );
		self::assertEquals( new TypeRule( true ), $service->mimeRules()['image/png'] );
		self::assertArrayNotHasKey( 'text/plain', $service->mimeRules() );
		self::assertSame( [ 'png' ], array_keys( $service->extensionRules() ) );
		self::assertSame(
			[
				'mimetypes.image/tiff: enabled is not true or false; the shipped default applies',
				'mimetypes.text/plain: not a MIME type this app handles; ignored',
				'extensions.TIF: not a lower-case extension; ignored',
				'extensions.jpg: min_size_mb is above max_size_mb; ignored',
			],
			$service->problems(),
		);
	}

	private function service( bool $marking = false ): ConfigurationService
	{
		return new ConfigurationService(
			$this->memoryAppConfig(),
			$marking ? $this->markingL10n() : $this->englishL10n(),
		);
	}
}
