<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Unit\Service;

use OCA\LargeFilePreviews\Service\RenderOutcome;
use OCA\LargeFilePreviews\Service\Sandbox;
use OCA\LargeFilePreviews\Tests\Unit\MemoryAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * What a sandbox shows a program, for each layout a system may have, and
 * when it is used; whether bwrap can start one is the integration tests'.
 */
class SandboxTest
    extends
    TestCase
{
	use MemoryAppConfig;


//  other non-static methods

	/**
	 * Merged /usr: the top-level directories are symlinks, made as such.
	 * The system read-only, fresh namespaces, the render's folder writable.
	 */
	public function testAMergedUsrSystem(): void
	{
		self::assertSame(
			[
				'--unshare-all', '--die-with-parent', '--new-session',
				'--ro-bind', '/usr', '/usr',
				'--symlink', 'usr/bin', '/bin',
				'--symlink', 'usr/lib', '/lib',
				'--symlink', 'usr/lib64', '/lib64',
				'--ro-bind', '/etc', '/etc',
				'--ro-bind', '/var/cache/fontconfig', '/var/cache/fontconfig',
				'--proc', '/proc', '--dev', '/dev', '--tmpfs', '/tmp',
				'--bind', '/tmp/oc_tmp_render', '/tmp/oc_tmp_render',
			],
			Sandbox::arguments( [ '/bin' => 'usr/bin', '/lib' => 'usr/lib', '/lib64' => 'usr/lib64' ], '/usr/bin/pdftoppm', '/tmp/oc_tmp_render', true ),
		);
	}

	/**
	 * Elsewhere they are directories, bound read-only; a program under one
	 * needs nothing of its own.
	 */
	public function testTopLevelDirectoriesAreBoundAsTheyAre(): void
	{
		$arguments = Sandbox::arguments( [ '/bin' => '', '/lib' => '' ], '/bin/pdftoppm', null, false );

		self::assertSame(
			[
				'--unshare-all', '--die-with-parent', '--new-session',
				'--ro-bind', '/usr', '/usr',
				'--ro-bind', '/bin', '/bin',
				'--ro-bind', '/lib', '/lib',
				'--ro-bind', '/etc', '/etc',
				'--proc', '/proc', '--dev', '/dev', '--tmpfs', '/tmp',
			],
			$arguments,
		);
	}

	/**
	 * A program a path in system config puts outside the system's folders
	 * has its own folder, read-only; one beside a system folder's name is
	 * not inside it.
	 */
	public function testAProgramOutsideTheSystemsFoldersBringsItsOwn(): void
	{
		$arguments = implode( ' ', Sandbox::arguments( [ '/lib' => 'usr/lib' ], '/opt/poppler/bin/pdftoppm', null, false ) );

		self::assertStringContainsString( '--ro-bind /opt/poppler/bin /opt/poppler/bin', $arguments );
		self::assertStringContainsString(
			'--ro-bind /usr2/bin /usr2/bin',
			implode( ' ', Sandbox::arguments( [], '/usr2/bin/pdftoppm', null, false ) ),
		);
		self::assertStringNotContainsString( '--bind', str_replace( '--ro-bind', '', $arguments ) );
	}

	/**
	 * Nothing writable without a folder: a run that writes nothing gets none.
	 */
	public function testNoFolderNoWrites(): void
	{
		self::assertNotContains( '--bind', Sandbox::arguments( [], '/usr/bin/pdfinfo', null, true ) );
	}

	/**
	 * The probe's answer is kept, and a bwrap that could not start one is
	 * reported with what it said.
	 */
	public function testTheProbesAnswerIsKept(): void
	{
		$sandbox = new Sandbox( $this->binaryLocator( [ 'bwrap' => '/usr/bin/bwrap' ] ), $this->detectionCache() );

		$this->cacheValues[ Sandbox::probeKey( '/usr/bin/bwrap' ) ] = '';
		self::assertNull( $sandbox->problem() );

		$this->cacheValues[ Sandbox::probeKey( '/usr/bin/bwrap' ) ] = 'No permissions to create new namespace';
		self::assertSame( 'No permissions to create new namespace', $sandbox->problem() );

		$wrapped = $sandbox->wrap( '/usr/bin/pdftoppm', '/render' );
		self::assertInstanceOf( RenderOutcome::class, $wrapped );
		self::assertStringContainsString( 'cannot start a sandbox here: No permissions to create new namespace', $wrapped->reason );
	}

	public function testWithoutBwrapThereIsNoSandbox(): void
	{
		$sandbox = new Sandbox( $this->binaryLocator( [] ), $this->detectionCache() );

		self::assertSame( 'bwrap is not found', $sandbox->problem() );
	}

	/**
	 * Usable, bwrap comes first, and `--` ends what is its.
	 */
	public function testAUsableBwrapWrapsTheProgram(): void
	{
		$sandbox                                                    = new Sandbox( $this->binaryLocator( [ 'bwrap' => '/usr/bin/bwrap' ] ), $this->detectionCache() );
		$this->cacheValues[ Sandbox::probeKey( '/usr/bin/bwrap' ) ] = '';
		$wrapped                                                    = $sandbox->wrap( '/usr/bin/pdftoppm', '/render' );

		self::assertIsArray( $wrapped );
		self::assertSame( '/usr/bin/bwrap', $wrapped[0] );
		self::assertSame( '--', $wrapped[ count( $wrapped ) - 1 ] );
		self::assertSame( [ '--bind', '/render', '/render' ], array_slice( $wrapped, -4, 3 ) );
	}
}
