<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Unit\Service;

use OCA\LargeFilePreviews\Service\ScratchDirectory;
use OCA\LargeFilePreviews\Tests\Unit\MemoryAppConfig;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;

class ScratchDirectoryTest
    extends
    TestCase
{
	use MemoryAppConfig;


//  constants

	private const MOUNTS
		 = <<<'TXT'
		zstore/root / zfs rw,relatime 0 0
		tmpfs /tmp tmpfs rw,nosuid,nodev 0 0
		zstore/data /store/data zfs rw 0 0
		tmpfs /store/data/cache\040dir tmpfs rw 0 0
		TXT;


//  other non-static methods

	/**
	 * The longest mount point holding the path; a mount point's escaped
	 * space read as one.
	 */
	public function testTheFileSystemAPathIsOn(): void
	{
		self::assertSame( 'tmpfs', ScratchDirectory::fileSystem( '/tmp/oc_tmp', self::MOUNTS ) );
		self::assertSame( 'zfs', ScratchDirectory::fileSystem( '/tmpdata', self::MOUNTS ) );
		self::assertSame( 'zfs', ScratchDirectory::fileSystem( '/store/data/helioscloud', self::MOUNTS ) );
		self::assertSame( 'tmpfs', ScratchDirectory::fileSystem( '/store/data/cache dir/x', self::MOUNTS ) );
		self::assertNull( ScratchDirectory::fileSystem( '/x', '' ) );
	}

	/**
	 * Nextcloud's temporary directory unless system config names another.
	 */
	public function testThePath(): void
	{
		self::assertSame( '/var/tmp/nc', $this->scratch( '/var/tmp/nc/' )->path() );

		$this->systemValues = [ ScratchDirectory::SYSTEM_KEY => '/srv/scratch' ];

		self::assertSame( '/srv/scratch', $this->scratch( '/var/tmp/nc' )->path() );
		self::assertSame( '/srv/scratch', $this->scratch( '/var/tmp/nc' )->override() );
	}

	public function testWhatCannotTakeACopy(): void
	{
		$this->systemValues = [ ScratchDirectory::SYSTEM_KEY => __FILE__ ];

		self::assertStringContainsString( 'is not an absolute, writable directory', (string) $this->scratch( '/' )->problem( 1 ) );

		// Relative, as only a hand-written config file could make it.
		$this->systemValues = [ ScratchDirectory::SYSTEM_KEY => 'tmp' ];

		self::assertStringContainsString( 'is not an absolute, writable directory', (string) $this->scratch( '/' )->problem( 1 ) );

		$this->systemValues = [ ScratchDirectory::SYSTEM_KEY => sys_get_temp_dir() ];

		self::assertMatchesRegularExpression( '/has \d+ MB free, and the copy takes \d+ MB|is on (tmpfs|ramfs)/', (string) $this->scratch( '/' )->problem( PHP_INT_MAX >> 1 ) );
	}

	/**
	 * One copy at a time: a second lock waits, and gives up after the time
	 * given; closing the first lets the next in.
	 */
	public function testOneCopyAtATime(): void
	{
		$folder = sys_get_temp_dir() . '/lfp-scratch-' . bin2hex( random_bytes( 4 ) );
		mkdir( $folder );
		$this->systemValues = [ ScratchDirectory::SYSTEM_KEY => $folder ];

		try
		{
			$first = $this->scratch( '/' )->lock( 0 );

			self::assertIsResource( $first );

			// flock() is per open file: a second opening of the lock file waits.
			$start = microtime( true );

			self::assertNull( $this->scratch( '/' )->lock( 1 ) );
			self::assertGreaterThanOrEqual( 0.9, microtime( true ) - $start );

			fclose( $first );

			$second = $this->scratch( '/' )->lock( 0 );

			self::assertIsResource( $second );
			fclose( $second );
		}
		finally
		{
			@unlink( $folder . '/' . ScratchDirectory::LOCK_FILE );
			@rmdir( $folder );
		}
	}

	private function scratch( string $temporary ): ScratchDirectory
	{
		$tempManager = $this->createMock( ITempManager::class );
		$tempManager->method( 'getTempBaseDir' )
		            ->willReturn( $temporary )
		;

		return new ScratchDirectory( $this->systemConfig(), $tempManager );
	}
}
