<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Unit\Migration;

use OCA\LargeFilePreviews\Migration\RefreshDetection;
use OCA\LargeFilePreviews\Service\BinaryLocator;
use OCA\LargeFilePreviews\Service\Prerequisite;
use OCA\LargeFilePreviews\Service\PrerequisitesService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class RefreshDetectionTest
    extends
    TestCase
{

//  other non-static methods

	/**
	 * Forgets, finds again with versions, says what it found, and warns of
	 * what is missing without failing the upgrade.
	 */
	public function testItFindsTheProgramsAgainAndReports(): void
	{
		$binaries = $this->createMock( BinaryLocator::class );
		$binaries->expects( self::once() )
		         ->method( 'refresh' )
		;

		$prerequisites = $this->createMock( PrerequisitesService::class );
		$prerequisites->expects( self::once() )
		              ->method( 'check' )
		              ->with( true )
		              ->willReturn(
			              [
				              new Prerequisite( Prerequisite::GROUP_EXTENSION, 'gd', Prerequisite::OK, 'loading' ),
				              new Prerequisite( Prerequisite::GROUP_PROGRAM, 'pdftoppm', Prerequisite::OK, 'rendering', '/usr/bin/pdftoppm (finder)' ),
				              new Prerequisite( Prerequisite::GROUP_PROGRAM, 'choom', Prerequisite::MISSING, 'the OOM score' ),
			              ],
		              )
		;

		$output = $this->createMock( IOutput::class );
		$output->expects( self::exactly( 2 ) )
		       ->method( 'info' )
		;
		$output->expects( self::once() )
		       ->method( 'warning' )
		       ->with( 'choom is missing: the OOM score' )
		;

		( new RefreshDetection( $binaries, $prerequisites ) )->run( $output );
	}
}
