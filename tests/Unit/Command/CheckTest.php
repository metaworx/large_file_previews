<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Unit\Command;

use OCA\LargeFilePreviews\Command\Check;
use OCA\LargeFilePreviews\Service\BinaryLocator;
use OCA\LargeFilePreviews\Service\Prerequisite;
use OCA\LargeFilePreviews\Service\PrerequisitesService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class CheckTest
    extends
    TestCase
{

//  other non-static methods

	public function testReadyExitsZero(): void
	{
		$tester = $this->tester( [ new Prerequisite( Prerequisite::GROUP_PROGRAM, 'pdftoppm', Prerequisite::OK, 'rendering', '/usr/bin/pdftoppm' ) ], false );

		self::assertSame( Command::SUCCESS, $tester->execute( [] ) );
		self::assertStringContainsString( 'Ready.', $tester->getDisplay() );
		self::assertSame(
			'large-file-previews:check',
			( new Check( $this->createMock( PrerequisitesService::class ), $this->createMock( BinaryLocator::class ) ) )->getName(),
		);
	}

	public function testRefreshFindsTheProgramsAgain(): void
	{
		$binaries = $this->createMock( BinaryLocator::class );
		$binaries->expects( self::once() )
		         ->method( 'refresh' )
		;

		$tester = $this->tester( [], false, $binaries );
		$tester->execute( [ '--refresh' => true ] );
		$tester->execute( [] );
	}

	public function testSomethingMissingExitsOneInEveryFormat(): void
	{
		$tester = $this->tester( [ new Prerequisite( Prerequisite::GROUP_PROGRAM, 'pdftoppm', Prerequisite::MISSING, 'rendering' ) ], true );

		self::assertSame( Command::FAILURE, $tester->execute( [] ) );
		self::assertStringContainsString( 'Not ready', $tester->getDisplay() );

		self::assertSame( Command::FAILURE, $tester->execute( [ '--output' => 'json' ] ) );
		$result = json_decode( $tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR );

		self::assertFalse( $result['ready'] );
		self::assertSame( 'missing', $result['items'][0]['status'] );
	}

	/**
	 * @param list<Prerequisite> $items
	 */
	private function tester(
		array          $items,
		bool           $blocking,
		?BinaryLocator $binaries = null,
	): CommandTester
	{
		$prerequisites = $this->createMock( PrerequisitesService::class );
		$prerequisites->method( 'check' )
		              ->with( true )
		              ->willReturn( $items )
		;
		$prerequisites->method( 'blocking' )
		              ->willReturn( $blocking )
		;

		return new CommandTester( new Check( $prerequisites, $binaries ?? $this->createMock( BinaryLocator::class ) ) );
	}
}
