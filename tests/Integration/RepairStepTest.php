<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Integration;

use OCA\LargeFilePreviews\AppInfo\Application;
use OCA\LargeFilePreviews\Config\ConfigLexicon;
use OCA\LargeFilePreviews\Migration\RemoveObsoleteKeys;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Server;

/**
 * The repair step against the server's own app config: an obsolete value,
 * stored as an upgrade finds it, is gone afterwards, and a current one is
 * kept.
 */
class RepairStepTest
    extends
    ServerTestCase
{

//  other non-static methods

	public function testAnObsoleteKeyIsRemoved(): void
	{
		// IAppConfig writes no key the lexicon does not declare; the row is
		// written as an earlier version left it.
		$query = Server::get( IDBConnection::class )->getQueryBuilder();
		$query->insert( 'appconfig' )
		      ->values(
			      [
				      'appid'       => $query->createNamedParameter( Application::APP_ID ),
				      'configkey'   => $query->createNamedParameter( 'vips_block_untrusted' ),
				      'configvalue' => $query->createNamedParameter( '0' ),
				      'type'        => $query->createNamedParameter( IAppConfig::VALUE_BOOL, IQueryBuilder::PARAM_INT ),
				      'lazy'        => $query->createNamedParameter( 0, IQueryBuilder::PARAM_INT ),
			      ],
		      )
		      ->executeStatement()
		;
		$this->appConfig()->clearCache();
		$this->settings()->setScalar( ConfigLexicon::TIMEOUT_SECONDS, 45 );

		self::assertContains( 'vips_block_untrusted', $this->appConfig()->getKeys( Application::APP_ID ) );

		Server::get( RemoveObsoleteKeys::class )->run( $this->createMock( IOutput::class ) );

		self::assertNotContains( 'vips_block_untrusted', $this->appConfig()->getKeys( Application::APP_ID ) );
		self::assertSame( 45, $this->settings()->getScalar( ConfigLexicon::TIMEOUT_SECONDS ) );
	}
}
