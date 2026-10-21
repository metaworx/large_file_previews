<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Integration;

use OCA\LargeFilePreviews\AppInfo\Application;
use OCA\LargeFilePreviews\Config\ConfigLexicon;
use OCA\LargeFilePreviews\Config\SupportedTypes;
use OCA\LargeFilePreviews\Config\TypeRule;
use OCA\LargeFilePreviews\Preview\LargeFileProvider;
use OCA\LargeFilePreviews\Renderer\VipsRenderer;
use OCA\LargeFilePreviews\Service\BinaryLocator;
use OCA\LargeFilePreviews\Service\CommandRunner;
use OCA\LargeFilePreviews\Service\RunOptions;
use OCA\LargeFilePreviews\Service\Sandbox;
use OCP\IConfig;
use OCP\Server;

/**
 * Renders in the bubblewrap sandbox, and what a program in it cannot
 * reach. Needs bwrap and user namespaces: skipped where bwrap cannot start
 * a sandbox, as in a container that refuses them - the harness's.
 */
class SandboxRenderTest
    extends
    ServerTestCase
{

//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$problem = Server::get( Sandbox::class )->problem();

		if ( $problem !== null )
		{
			self::markTestSkipped( 'bwrap cannot start a sandbox here: ' . $problem );
		}

		foreach ( SupportedTypes::DOCUMENT_TYPES as $mime )
		{
			$this->settings()->setMimeRule( $mime, new TypeRule( true ) );
		}

		$this->settings()->setScalar( ConfigLexicon::SANDBOX, ConfigLexicon::SANDBOX_ON );
	}


//  other non-static methods

	public function testAPdfIsRenderedInTheSandbox(): void
	{
		$image = Server::get( LargeFileProvider::class )->getThumbnail( $this->file( 'sandboxed.pdf', PdfFixture::bytes( 200, 100 ) ), 256, 256 );

		self::assertNotNull( $image );
		self::assertSame( 256, $image->width() );
		self::assertNull( $this->appConfig()->getValueArray( Application::APP_ID, ConfigLexicon::LAST_FAILURE ) ?: null );
	}

	/**
	 * Auto, the default, sandboxes where the process rendering can start a
	 * sandbox, as this one can.
	 */
	public function testAutoSandboxesWhereItCan(): void
	{
		$this->settings()->setScalar( ConfigLexicon::SANDBOX, ConfigLexicon::SANDBOX_AUTO );

		$command = Server::get( CommandRunner::class )->command( 'pdftoppm', [ '-v' ] );
		$bwrap   = (string) Server::get( BinaryLocator::class )->locate( Sandbox::PROGRAM )->path;

		self::assertIsArray( $command );
		self::assertContains( $bwrap, $command );
		self::assertNotNull( Server::get( LargeFileProvider::class )->getThumbnail( $this->file( 'auto.pdf', PdfFixture::bytes( 200, 100 ) ), 256, 256 ) );
	}

	public function testAnImageIsRenderedInTheSandbox(): void
	{
		if ( ! Server::get( BinaryLocator::class )->locate( VipsRenderer::PROGRAM )->isFound() )
		{
			self::markTestSkipped( 'libvips is not installed here.' );
		}

		$this->settings()->setMimeRule( 'image/png', new TypeRule( true, 0 ) );
		$gd = imagecreatetruecolor( 400, 200 );
		ob_start();
		imagepng( $gd );
		$image = Server::get( LargeFileProvider::class )->getThumbnail( $this->file( 'sandboxed.png', (string) ob_get_clean() ), 256, 256 );

		self::assertNotNull( $image );
		self::assertSame( 256, $image->width() );
	}

	/**
	 * A program in the sandbox sees neither the data directory nor
	 * Nextcloud's config, both there outside it, and has no network but
	 * its own loopback.
	 */
	public function testWhatASandboxedProgramCannotReach(): void
	{
		$data   = Server::get( IConfig::class )->getSystemValueString( 'datadirectory', '' );
		$config = '';

		foreach ( get_included_files() as $file )
		{
			if ( basename( $file ) === 'config.php' && str_contains( $file, '/config/' ) )
			{
				$config = $file;

				break;
			}
		}

		self::assertDirectoryExists( $data );
		self::assertFileExists( $config );

		$outcome = Server::get( CommandRunner::class )->run(
			'sh',
			[
				'-c',
				'ls "$1" >/dev/null 2>&1 && echo data; test -e "$2" && echo config; tail -n +3 /proc/net/dev | cut -d: -f1 | tr -d " "; echo end',
				'sh',
				$data,
				$config,
			],
			new RunOptions( keepStdout: true ),
		);

		self::assertTrue( $outcome->isSuccess(), $outcome->reason );
		self::assertSame( "lo\nend", trim( $outcome->stderr ) );
	}
}
