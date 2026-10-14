<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Unit\Migration;

use OCA\LargeFilePreviews\AppInfo\Application;
use OCA\LargeFilePreviews\Migration\RemoveObsoleteKeys;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class RemoveObsoleteKeysTest
    extends
    TestCase
{

//  other non-static methods

	/**
	 * Only an obsolete key that is stored is deleted, and said; a current
	 * key, and one not stored, are left alone.
	 */
	public function testObsoleteKeysStoredAreRemoved(): void
	{
		$appConfig = $this->createMock( IAppConfig::class );
		$appConfig->method( 'getKeys' )
		          ->with( Application::APP_ID )
		          ->willReturn( [ 'mimetypes', 'timeout_seconds', 'vips_block_untrusted' ] )
		;
		$appConfig->expects( self::once() )
		          ->method( 'deleteKey' )
		          ->with( Application::APP_ID, 'vips_block_untrusted' )
		;

		$output = $this->createMock( IOutput::class );
		$output->expects( self::once() )
		       ->method( 'info' )
		       ->with( 'Removed the obsolete setting vips_block_untrusted' )
		;

		( new RemoveObsoleteKeys( $appConfig ) )->run( $output );
	}

	public function testNothingToRemove(): void
	{
		$appConfig = $this->createMock( IAppConfig::class );
		$appConfig->method( 'getKeys' )
		          ->willReturn( [ 'timeout_seconds' ] )
		;
		$appConfig->expects( self::never() )
		          ->method( 'deleteKey' )
		;

		( new RemoveObsoleteKeys( $appConfig ) )->run( $this->createMock( IOutput::class ) );
	}
}
