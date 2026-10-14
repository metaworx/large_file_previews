<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Unit\SetupCheck;

use DateTime;
use DateTimeZone;
use OCA\LargeFilePreviews\Config\ConfigLexicon;
use OCA\LargeFilePreviews\Service\Prerequisite;
use OCA\LargeFilePreviews\Service\PrerequisitesService;
use OCA\LargeFilePreviews\SetupCheck\RendererCheck;
use OCA\LargeFilePreviews\Tests\Unit\MemoryAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\SetupCheck\SetupResult;
use PHPUnit\Framework\TestCase;

class RendererCheckTest
    extends
    TestCase
{
	use MemoryAppConfig;


//  constants

	private const NOW = 1_760_000_000;


//  other non-static methods

	public function testReady(): void
	{
		$result = $this->check( [] )->run();

		self::assertSame( SetupResult::SUCCESS, $result->getSeverity() );
		self::assertSame( '«Large previews can be rendered.»', $result->getDescription() );
	}

	public function testWhatIsMissingIsAnErrorNamingEachThing(): void
	{
		$result = $this->check(
			[
				new Prerequisite( Prerequisite::GROUP_PERMISSION, 'proc_open', Prerequisite::DISABLED, 'x' ),
				new Prerequisite( Prerequisite::GROUP_EXTENSION, 'gd', Prerequisite::MISSING, 'x' ),
				new Prerequisite( Prerequisite::GROUP_PROGRAM, 'pdftoppm', Prerequisite::MISSING, 'x' ),
				new Prerequisite( Prerequisite::GROUP_PROGRAM, 'choom', Prerequisite::OPTIONAL, 'x' ),
			],
		)->run();

		self::assertSame( SetupResult::ERROR, $result->getSeverity() );
		self::assertSame(
			'«Large previews cannot be rendered: «the PHP function proc_open is disabled», «the PHP extension gd is missing», «the program pdftoppm is missing». See occ large-file-previews:check.»',
			$result->getDescription(),
		);
	}

	public function testARecentFailureIsAWarning(): void
	{
		$this->appValues[ ConfigLexicon::LAST_FAILURE ] = [
			'time'        => self::NOW - 3600,
			'mime'        => 'application/pdf',
			'outcome'     => 'timed_out',
			'reason'      => 'timed out (exit status 124)',
			'exit_status' => 124,
		];

		$result = $this->check( [] )->run();

		self::assertSame( SetupResult::WARNING, $result->getSeverity() );
		self::assertSame(
			'«The last large preview that failed, at 2025-10-09 07:53:20 UTC, was a file of type application/pdf: timed out (exit status 124).»',
			$result->getDescription(),
		);
	}

	public function testAnOldFailureIsForgotten(): void
	{
		$this->appValues[ ConfigLexicon::LAST_FAILURE ] = [
			'time'   => self::NOW - RendererCheck::RECENT_SECONDS,
			'mime'   => 'application/pdf',
			'reason' => 'exit status 1',
		];

		self::assertSame( SetupResult::SUCCESS, $this->check( [] )->run()->getSeverity() );
	}

	public function testAGarbledRecordIsIgnored(): void
	{
		$this->appValues[ ConfigLexicon::LAST_FAILURE ] = [ 'time' => 'yesterday' ];

		self::assertSame( SetupResult::SUCCESS, $this->check( [] )->run()->getSeverity() );
	}

	/**
	 * @param list<Prerequisite> $items
	 */
	private function check( array $items ): RendererCheck
	{
		$prerequisites = $this->createMock( PrerequisitesService::class );
		$prerequisites->method( 'check' )
		              ->willReturn( $items )
		;

		$time = $this->createMock( ITimeFactory::class );
		$time->method( 'getTime' )
		     ->willReturn( self::NOW )
		;
		$time->method( 'getDateTime' )
		     ->willReturnCallback(
			     static fn(
				     string $at,
			     ): DateTime => ( new DateTime( $at ) )->setTimezone( new DateTimeZone( 'UTC' ) ),
		     )
		;

		return new RendererCheck(
			$prerequisites,
			$this->settings(),
			$time,
			$this->markingL10n(),
		);
	}
}
