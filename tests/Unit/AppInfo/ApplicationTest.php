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
		self::assertSame( Application::APP_ID, (string) self::manifest()->id );
	}

	public function testNamespaceMatchesTheManifest(): void
	{
		self::assertSame(
			'OCA\\' . (string) self::manifest()->namespace . '\\AppInfo\\Application',
			Application::class,
		);
	}


//  static methods

	/**
	 * Read as a string: a booted server forbids libxml to load files itself
	 * (its guard against external entities), so simplexml_load_file() fails
	 * there.
	 */
	private static function manifest(): \SimpleXMLElement
	{
		$xml = file_get_contents( dirname( __DIR__, 3 ) . '/appinfo/info.xml' );
		self::assertIsString( $xml );

		$manifest = simplexml_load_string( $xml );
		self::assertNotFalse( $manifest );

		return $manifest;
	}
}
