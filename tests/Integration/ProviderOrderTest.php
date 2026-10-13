<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Integration;

use OCA\LargeFilePreviews\Preview\LargeFileProvider;
use OCP\IPreview;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Pins behaviour internal to the server, which the app depends on and the
 * contract lists (3.2): providers are tried in the order of their regex's
 * length, longest first, so the app's provider is asked first for every
 * type it registers. Re-run for every new Nextcloud release.
 */
class ProviderOrderTest
    extends
    ServerTestCase
{

//  other non-static methods

	#[DataProvider( 'types' )]
	public function testTheAppIsAskedFirst( string $mime ): void
	{
		$first = null;

		foreach ( Server::get( IPreview::class )->getProviders() as $regex => $closures )
		{
			if ( preg_match( $regex, $mime ) === 1 && $closures !== [] )
			{
				$first = $closures[0]();

				break;
			}
		}

		self::assertInstanceOf( LargeFileProvider::class, $first, 'The first provider for ' . $mime );
	}


//  static methods

	/**
	 * @return array<string, array{string}>
	 */
	public static function types(): array
	{
		return [
			'PDF'         => [ 'application/pdf' ],
			'Illustrator' => [ 'application/illustrator' ],
			// Core's Imaginary provider claims TIFF with the longest regex of
			// its own, 92 characters.
			'TIFF'        => [ 'image/tiff' ],
			'JPEG'        => [ 'image/jpeg' ],
		];
	}
}
