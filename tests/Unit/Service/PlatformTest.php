<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Unit\Service;

use OCA\LargeFilePreviews\Service\BinaryLocator;
use OCA\LargeFilePreviews\Service\Platform;
use PHPUnit\Framework\TestCase;

class PlatformTest
    extends
    TestCase
{

//  other non-static methods

	/**
	 * By ID, and else by ID_LIKE: a RHEL rebuild that names Fedora too is
	 * RHEL, as its ID says.
	 */
	public function testTheFamilyOfEachOsRelease(): void
	{
		self::assertSame( Platform::DEBIAN, self::platform( 'ubuntu' )->family() );
		self::assertSame( Platform::DEBIAN, self::platform( 'debian' )->family() );
		self::assertSame( Platform::FEDORA, self::platform( 'fedora' )->family() );
		self::assertSame( Platform::RHEL, self::platform( 'rocky' )->family() );
		self::assertSame( Platform::ALPINE, self::platform( 'alpine' )->family() );
		self::assertSame( Platform::ARCH, self::platform( 'arch' )->family() );
		self::assertNull( self::platform( 'opensuse' )->family() );
		self::assertNull( self::platform( 'none' )->family() );
		self::assertSame( Platform::DEBIAN, Platform::familyOf( "ID=linuxmint\nID_LIKE=\"ubuntu debian\"" ) );
	}

	public function testEachFamilyInstallsEachProgram(): void
	{
		self::assertSame( [ 'command' => 'apt install libvips-tools', 'note' => null ], self::platform( 'ubuntu' )->install( 'vipsthumbnail' ) );
		self::assertSame( [ 'command' => 'dnf install vips-tools', 'note' => null ], self::platform( 'fedora' )->install( 'vips' ) );
		self::assertSame( [ 'command' => 'apk add util-linux-misc', 'note' => null ], self::platform( 'alpine' )->install( 'choom' ) );
		self::assertSame( [ 'command' => 'pacman -S poppler', 'note' => null ], self::platform( 'arch' )->install( 'pdftoppm' ) );
		self::assertSame( [ 'command' => 'apk add vips-tools', 'note' => 'community' ], self::platform( 'alpine' )->install( 'vipsheader' ) );

		// Every program the app runs has a package in every family, or a note.
		foreach ( [ 'ubuntu', 'fedora', 'rocky', 'alpine', 'arch' ] as $name )
		{
			foreach ( BinaryLocator::PROGRAMS as $program )
			{
				$install = self::platform( $name )->install( $program );

				self::assertNotNull( $install['command'] ?? $install['note'] ?? null, $name . ' ' . $program );
			}
		}
	}

	/**
	 * RHEL: libvips is packaged nowhere, libtiff-tools is in CRB.
	 */
	public function testRhelSaysWhatItLacks(): void
	{
		$rocky = self::platform( 'rocky' );

		self::assertSame( [ 'command' => null, 'note' => 'not-packaged' ], $rocky->install( 'vipsthumbnail' ) );
		self::assertSame( 'packaged for neither RHEL nor EPEL', Platform::text( $rocky->install( 'vips' ) ) );
		self::assertSame( 'dnf install libtiff-tools, from the CRB repository', Platform::text( $rocky->install( 'tiffcp' ) ) );
		self::assertSame( 'RHEL or a rebuild', $rocky->name() );
	}

	public function testNothingWhereNothingIsKnown(): void
	{
		self::assertNull( self::platform( 'opensuse' )->install( 'pdftoppm' ) );
		self::assertNull( self::platform( 'ubuntu' )->install( 'bash' ) );
		self::assertSame( '', Platform::text( null ) );
	}


//  static methods

	private static function platform( string $name ): Platform
	{
		return new Platform( dirname( __DIR__, 2 ) . '/fixtures/os-release/' . $name );
	}
}
