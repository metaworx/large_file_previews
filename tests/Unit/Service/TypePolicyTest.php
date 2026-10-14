<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Unit\Service;

use OCA\LargeFilePreviews\Config\ConfigLexicon;
use OCA\LargeFilePreviews\Config\Resolution;
use OCA\LargeFilePreviews\Config\Source;
use OCA\LargeFilePreviews\Config\Verdict;
use OCA\LargeFilePreviews\Service\ConfigurationService;
use OCA\LargeFilePreviews\Service\TypePolicy;
use OCA\LargeFilePreviews\Tests\Unit\EnglishL10n;
use OCA\LargeFilePreviews\Tests\Unit\MemoryAppConfig;
use OCP\Files\FileInfo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TypePolicyTest
    extends
    TestCase
{
	use EnglishL10n;
	use MemoryAppConfig;


//  other non-static methods

	public function testAppDefaultsApplyWhereNothingElseSays(): void
	{
		$this->appValues = [
			ConfigLexicon::MIN_SIZE_MB => 1,
			ConfigLexicon::MAX_SIZE_MB => 900,
		];

		$resolution = $this->policy()->resolve( 'application/pdf', null );

		self::assertTrue( $resolution->enabled );
		self::assertSame( Source::MIME, $resolution->enabledSource );
		self::assertSame( [ 1, Source::DEFAULT ], [ $resolution->minSizeMb, $resolution->minSource ] );
		self::assertSame( [ 900, Source::DEFAULT ], [ $resolution->maxSizeMb, $resolution->maxSource ] );
	}

	/**
	 * Field by field: the extension sets the minimum only, so the MIME
	 * type's switch and the app's maximum still hold.
	 */
	public function testTheMostSpecificLevelWinsFieldByField(): void
	{
		$this->appValues = [
			ConfigLexicon::MAX_SIZE_MB => 4000,
			ConfigLexicon::MIMETYPES   => [ 'image/tiff' => [ 'enabled' => true, 'min_size_mb' => 50 ] ],
			ConfigLexicon::EXTENSIONS  => [ 'tif' => [ 'min_size_mb' => 200 ] ],
		];

		$resolution = $this->policy()->resolve( 'image/tiff', 'TIF' );

		self::assertSame( [ true, Source::MIME ], [ $resolution->enabled, $resolution->enabledSource ] );
		self::assertSame( [ 200, Source::EXTENSION ], [ $resolution->minSizeMb, $resolution->minSource ] );
		self::assertSame( [ 4000, Source::DEFAULT ], [ $resolution->maxSizeMb, $resolution->maxSource ] );
		self::assertSame( 'tif', $resolution->extension );
	}

	public function testAnExtensionCanSwitchOffAnEnabledType(): void
	{
		$this->appValues = [ ConfigLexicon::EXTENSIONS => [ 'ai' => [ 'enabled' => false ] ] ];

		$resolution = $this->policy()->resolve( 'application/illustrator', 'ai' );

		self::assertSame( [ false, Source::EXTENSION ], [ $resolution->enabled, $resolution->enabledSource ] );
		self::assertSame( Verdict::DISABLED, $resolution->verdict( 1 ) );
	}

	public function testAnExtensionCanSwitchOnADisabledType(): void
	{
		$this->appValues = [ ConfigLexicon::EXTENSIONS => [ 'tif' => [ 'enabled' => true ] ] ];

		self::assertTrue(
			$this->policy()
			     ->resolve( 'image/tiff', 'tif' )
			     ->verdict( 60 * Resolution::BYTES_PER_MB )
			     ->isTaken(),
		);
	}

	public function testAnExtensionCannotMakeAnUnregisteredTypeReachable(): void
	{
		$this->appValues = [ ConfigLexicon::EXTENSIONS => [ 'txt' => [ 'enabled' => true ] ] ];

		$resolution = $this->policy()->resolve( 'text/plain', 'txt' );

		self::assertTrue( $resolution->enabled );
		self::assertSame( Verdict::UNREACHABLE, $resolution->verdict( 1 ) );
	}

	public function testNoMimeTypeIsUnreachable(): void
	{
		self::assertFalse( $this->policy()->resolve( null, 'pdf' )->reachable );
	}

	public function testTakesReadsTheFilesTypeNameAndSize(): void
	{
		$this->appValues = [ ConfigLexicon::EXTENSIONS => [ 'pdf' => [ 'max_size_mb' => 10 ] ] ];

		self::assertTrue( $this->policy()->takes( $this->file( 'application/pdf', 'Scan.PDF', 10 * Resolution::BYTES_PER_MB ) ) );
		self::assertFalse( $this->policy()->takes( $this->file( 'application/pdf', 'Scan.PDF', 10 * Resolution::BYTES_PER_MB + 1 ) ) );
		self::assertFalse( $this->policy()->takes( $this->file( 'image/png', 'big.png', 500 * Resolution::BYTES_PER_MB ) ) );
	}

	#[DataProvider( 'names' )]
	public function testExtensionOf(
		string  $name,
		?string $expected,
	): void
	{
		self::assertSame( $expected, TypePolicy::extensionOf( $name ) );
	}


//  static methods

	/**
	 * @return array<string, array{string, ?string}>
	 */
	public static function names(): array
	{
		return [
			'plain'        => [ 'scan.pdf', 'pdf' ],
			'upper case'   => [ 'SCAN.TIF', 'tif' ],
			'two dots'     => [ 'a.tar.gz', 'gz' ],
			'none'         => [ 'README', null ],
			'dot file'     => [ '.hidden', null ],
			'trailing dot' => [ 'name.', null ],
		];
	}

	private function policy(): TypePolicy
	{
		return new TypePolicy( new ConfigurationService( $this->memoryAppConfig(), $this->englishL10n(), $this->mimeDetector(), $this->systemConfig() ) );
	}

	private function file(
		string $mime,
		string $name,
		int    $size,
	): FileInfo
	{
		$file = $this->createMock( FileInfo::class );
		$file->method( 'getMimetype' )
		     ->willReturn( $mime )
		;
		$file->method( 'getName' )
		     ->willReturn( $name )
		;
		$file->method( 'getSize' )
		     ->willReturn( $size )
		;

		return $file;
	}
}
