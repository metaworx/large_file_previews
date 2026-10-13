<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Unit\AppInfo;

use OCA\LargeFilePreviews\AppInfo\Application;
use PHPUnit\Framework\TestCase;

/**
 * The app id is written in two places that Nextcloud reads separately: the
 * manifest, and the constant every lookup by app id uses.
 */
class ApplicationTest
    extends
    TestCase
{

//  other non-static methods

	public function testAppIdMatchesTheManifest(): void
	{
		$manifest = simplexml_load_file( dirname( __DIR__, 3 ) . '/appinfo/info.xml' );

		self::assertNotFalse( $manifest );
		self::assertSame( Application::APP_ID, (string) $manifest->id );
	}

	public function testNamespaceMatchesTheManifest(): void
	{
		$manifest = simplexml_load_file( dirname( __DIR__, 3 ) . '/appinfo/info.xml' );

		self::assertNotFalse( $manifest );
		self::assertSame(
			'OCA\\' . (string) $manifest->namespace . '\\AppInfo\\Application',
			Application::class,
		);
	}
}
