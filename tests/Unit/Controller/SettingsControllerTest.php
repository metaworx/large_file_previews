<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Unit\Controller;

use OCA\LargeFilePreviews\Config\ConfigLexicon;
use OCA\LargeFilePreviews\Controller\SettingsController;
use OCA\LargeFilePreviews\Renderer\PageProbe;
use OCA\LargeFilePreviews\Renderer\PopplerRenderer;
use OCA\LargeFilePreviews\Renderer\Renderers;
use OCA\LargeFilePreviews\Renderer\VipsLoaders;
use OCA\LargeFilePreviews\Renderer\VipsRenderer;
use OCA\LargeFilePreviews\Service\BinaryLocator;
use OCA\LargeFilePreviews\Service\CommandRunner;
use OCA\LargeFilePreviews\Service\PrerequisitesService;
use OCA\LargeFilePreviews\Service\ScratchDirectory;
use OCA\LargeFilePreviews\Service\TypePolicy;
use OCA\LargeFilePreviews\Settings\Admin;
use OCA\LargeFilePreviews\Tests\Unit\MemoryAppConfig;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class SettingsControllerTest
    extends
    TestCase
{
	use MemoryAppConfig;


//  other non-static methods

	/**
	 * Admin only, CSRF checked: no route may say otherwise.
	 */
	public function testEveryRouteIsForAdminsAndCsrfChecked(): void
	{
		$routes = array_filter(
			( new ReflectionClass( SettingsController::class ) )->getMethods( ReflectionMethod::IS_PUBLIC ),
			static fn(
				ReflectionMethod $method,
			): bool => $method->getDeclaringClass()->getName() === SettingsController::class && $method->getName() !== '__construct',
		);

		self::assertNotEmpty( $routes );

		foreach ( $routes as $method )
		{
			foreach ( [ NoAdminRequired::class, NoCSRFRequired::class, PublicPage::class ] as $attribute )
			{
				self::assertSame( [], $method->getAttributes( $attribute ), $method->getName() . ' has ' . $attribute );
			}
		}
	}

	public function testThePageIsInAdditionalSettings(): void
	{
		self::assertSame( 'additional', ( new Admin() )->getSection() );
	}

	public function testGetDescribesEverything(): void
	{
		$data = $this->controller( '' )->get()->getData();

		self::assertSame( [ 'scalars', 'active', 'mimetypes', 'extensions', 'prerequisites', 'ready', 'problems', 'lastFailure', 'overrideKey', 'programs', 'platform', 'scratch', 'untrusted', 'version' ], array_keys( $data ) );
		self::assertSame( '0.1.0', $data['version'] );
		self::assertSame( 'pdftoppm version 24.02.0', $data['programs'][0]['version'] );
		self::assertSame( [ 'path' => '/var/tmp/nextcloud', 'overridden' => false ], $data['scratch'] );
		self::assertSame( [ 'allowed' => false, 'blockable' => null ], $data['untrusted'] );
		self::assertArrayNotHasKey( 'vips_block_untrusted', $data['scalars'] );
		self::assertSame( [ 'nextcloud', 'png', 'jpeg', 'webp' ], $data['scalars']['preview_format']['choices'] );
		self::assertNull( $data['scalars']['timeout_seconds']['choices'] );
		self::assertSame( 30, $data['scalars']['timeout_seconds']['value'] );
		self::assertSame( 600, $data['scalars']['timeout_seconds']['max'] );
		self::assertCount( 16, $data['mimetypes'] );
		self::assertSame( 'application/pdf', $data['mimetypes'][0]['mime'] );
		self::assertSame( [ 'program' => 'pdftoppm', 'available' => true, 'unsupported' => null ], $data['mimetypes'][0]['renderer'] );
		self::assertSame( [ 'program' => 'vipsthumbnail', 'available' => false, 'unsupported' => null ], $data['mimetypes'][2]['renderer'] );
		self::assertSame(
			[ 'program' => 'vipsthumbnail', 'available' => false, 'unsupported' => [ 'decoder' => 'jp2kload', 'problem' => 'untrusted' ] ],
			$data['mimetypes'][10]['renderer'],
		);
		self::assertSame( 'large_file_previews.binaries', $data['overrideKey'] );
	}

	/**
	 * What is active out of the box: PDF and Illustrator, with where each
	 * size comes from.
	 */
	public function testActiveListsTheEnabledTypes(): void
	{
		$active = $this->controller( '' )->get()->getData()['active'];

		self::assertSame( [ 'application/pdf', 'application/illustrator' ], array_column( $active, 'mime' ) );
		self::assertSame(
			[
				'mime'        => 'application/pdf',
				'extension'   => null,
				'processing'  => 'enabled',
				'renderer'    => [ 'program' => 'pdftoppm', 'available' => true, 'unsupported' => null ],
				'min_size_mb' => [ 'value' => 0, 'source' => 'default' ],
				'max_size_mb' => [ 'value' => -1, 'source' => 'default' ],
			],
			$active[0],
		);
	}

	/**
	 * An extension enabled selects its files where its MIME type is off;
	 * excluded, never; enabled with no size where its MIME type is on, it
	 * changes nothing, and says so.
	 */
	/**
	 * A rule from before for a type the provider never sees names no
	 * renderer at all.
	 */
	public function testAnUnhandledTypeHasNoRenderer(): void
	{
		$this->appValues[ ConfigLexicon::EXTENSIONS ] = [ '7z' => [ 'enabled' => true ] ];

		$extensions = $this->controller( '' )->get()->getData()['extensions'];

		self::assertSame( [ 'program' => null, 'available' => false, 'unsupported' => null ], $extensions[0]['renderer'] );
		self::assertSame( 'application/x-7z-compressed', $extensions[0]['mime'] );
	}

	public function testExtensionsInTheActiveListAndTheirEffect(): void
	{
		$this->appValues[ ConfigLexicon::EXTENSIONS ] = [
			'tif' => [ 'enabled' => true, 'min_size_mb' => 200 ],
			'pdf' => [ 'enabled' => true ],
			'ai'  => [ 'enabled' => false ],
		];

		$data       = $this->controller( '' )->get()->getData();
		$extensions = array_column( $data['extensions'], null, 'extension' );
		$active     = array_column( array_filter( $data['active'], static fn( array $row ): bool => $row['extension'] !== null ), null, 'extension' );

		self::assertFalse( $extensions['tif']['noEffect'] );
		self::assertTrue( $extensions['pdf']['noEffect'] );
		self::assertFalse( $extensions['ai']['noEffect'] );

		self::assertSame( 'enabled', $active['tif']['processing'] );
		self::assertSame( [ 'value' => 200, 'source' => 'ext' ], $active['tif']['min_size_mb'] );
		self::assertFalse( $active['tif']['renderer']['available'] );
		self::assertSame( 'excluded', $active['ai']['processing'] );
	}

	public function testUpdateAppliesEachPart(): void
	{
		$this->appValues[ ConfigLexicon::EXTENSIONS ] = [ 'jpg' => [ 'enabled' => true ] ];

		$response = $this->controller(
			json_encode(
				[
					'scalars'    => [ 'timeout_seconds' => 60, 'choom_enabled' => false ],
					'mimetypes'  => [ 'image/tiff' => [ 'enabled' => true, 'min_size_mb' => null ] ],
					'extensions' => [ 'tif' => [ 'max_size_mb' => 300 ], 'jpg' => null ],
				],
				JSON_THROW_ON_ERROR,
			),
		)->update();

		self::assertSame( Http::STATUS_OK, $response->getStatus() );
		self::assertSame( 60, $this->appValues[ ConfigLexicon::TIMEOUT_SECONDS ] );
		self::assertFalse( $this->appValues[ ConfigLexicon::CHOOM_ENABLED ] );
		self::assertSame( [ 'image/tiff' => [ 'enabled' => true ] ], $this->appValues[ ConfigLexicon::MIMETYPES ] );
		self::assertSame( [ 'tif' => [ 'max_size_mb' => 300 ] ], $this->appValues[ ConfigLexicon::EXTENSIONS ] );
		self::assertSame( 60, $response->getData()['scalars']['timeout_seconds']['value'] );
	}

	public function testANullScalarReturnsToTheDefault(): void
	{
		$this->appValues[ ConfigLexicon::TIMEOUT_SECONDS ] = 60;

		$this->controller( '{"scalars":{"timeout_seconds":null}}' )->update();

		self::assertArrayNotHasKey( ConfigLexicon::TIMEOUT_SECONDS, $this->appValues );
	}

	/**
	 * Nothing of the body is written where one part is refused, a field
	 * cleared before it included; the answer carries the state as it is.
	 */
	public function testARefusalIsA400WithTheTranslatedReasonAndTheState(): void
	{
		$this->appValues = [ ConfigLexicon::EXTENSIONS => [ 'tif' => [ 'min_size_mb' => 10 ] ] ];
		$response        = $this->controller( '{"scalars":{"choom_adjust":500,"timeout_seconds":900},"extensions":{"tif":{"min_size_mb":null}}}' )->update();
		$data            = (array) $response->getData();

		self::assertSame( Http::STATUS_BAD_REQUEST, $response->getStatus() );
		self::assertSame( '«timeout_seconds must be between 1 and 600.»', $data['error'] ?? null );
		self::assertSame( [ 'error', 'state' ], array_keys( $data ) );
		self::assertSame( [ ConfigLexicon::EXTENSIONS => [ 'tif' => [ 'min_size_mb' => 10 ] ] ], $this->appValues );

		$response = $this->controller( '{"extensions":{"tif":{"min_size_mb":null,"max_size_mb":-5}}}' )->update();

		self::assertSame( Http::STATUS_BAD_REQUEST, $response->getStatus() );
		self::assertSame( [ ConfigLexicon::EXTENSIONS => [ 'tif' => [ 'min_size_mb' => 10 ] ] ], $this->appValues );
	}

	/**
	 * What system config holds that is not used is on the page too: an
	 * override for no program, a scratch directory that is none.
	 */
	public function testOverrideProblemsAreOnThePage(): void
	{
		$this->systemValues = [ ScratchDirectory::SYSTEM_KEY => 'relative/dir' ];

		$problems = $this->controller( '', overrides: [ 'pdftopm' => '/usr/bin/pdftoppm' ] )->get()->getData()['problems'] ?? null;

		self::assertSame(
			[
				'large_file_previews.binaries.pdftopm: not a program this app runs; ignored',
				'large_file_previews.scratch_directory: relative/dir is not an absolute path',
			],
			$problems,
		);
	}

	/**
	 * Refresh forgets every path found, then answers the whole state.
	 */
	public function testRefreshFindsProgramsAgain(): void
	{
		$this->cacheValues = [ 'path.pdftoppm' => '/old/pdftoppm', 'vips-loaders./usr/bin/vips' => '{}' ];

		$data = $this->controller( '' )->refresh()->getData();

		self::assertArrayNotHasKey( 'vips-loaders./usr/bin/vips', $this->cacheValues );
		self::assertSame( '/usr/bin/pdftoppm', $this->cacheValues['path.pdftoppm'] ?? null );
		self::assertArrayHasKey( 'programs', $data );
	}

	public function testWhatIsNotARequestIsRefused(): void
	{
		self::assertSame( Http::STATUS_BAD_REQUEST, $this->controller( 'not json' )->update()->getStatus() );
		self::assertSame( Http::STATUS_BAD_REQUEST, $this->controller( '{"mimetypes":{"image/png":"on"}}' )->update()->getStatus() );
		self::assertSame( Http::STATUS_BAD_REQUEST, $this->controller( '{"mimetypes":{"image/png":{"colour":1}}}' )->update()->getStatus() );
	}

	/**
	 * @param array<string, string> $overrides programs' paths in system config
	 */
	private function controller(
		string $body,
		array  $overrides = [],
	): SettingsController
	{
		$config = $this->settings( true );

		$prerequisites = $this->createMock( PrerequisitesService::class );
		$prerequisites->method( 'check' )
		              ->willReturn( [] )
		;
		$prerequisites->method( 'programList' )
		              ->willReturn( [ [ 'name' => 'pdftoppm', 'path' => '/usr/bin/pdftoppm', 'source' => 'finder', 'problem' => null, 'version' => 'pdftoppm version 24.02.0' ] ] )
		;

		$tempManager = $this->createMock( ITempManager::class );
		$tempManager->method( 'getTempBaseDir' )
		            ->willReturn( '/var/tmp/nextcloud' )
		;
		$scratch = new ScratchDirectory( $this->systemConfig(), $tempManager );

		// pdftoppm is there; nothing else is asked for.
		if ( $overrides !== [] )
		{
			$this->systemValues[ BinaryLocator::SYSTEM_KEY ] = $overrides;
		}

		$binaries = $this->binaryLocator( [ 'pdftoppm' => '/usr/bin/pdftoppm' ] );

		// libvips, where it were there, with TIFF and an untrusted JPEG 2000.
		$loaders = $this->createMock( VipsLoaders::class );
		$loaders->method( 'loaders' )
		        ->willReturn( [ 'tiffload' => false, 'jp2kload' => true ] )
		;

		$vips = new VipsRenderer( $this->createMock( CommandRunner::class ), $config, $loaders, $binaries, $this->createMock( ScratchDirectory::class ) );

		$apps = $this->createMock( IAppManager::class );
		$apps->method( 'getAppVersion' )
		     ->with( 'large_file_previews' )
		     ->willReturn( '0.1.0' )
		;

		return new class (
			$body,
			$this->createMock( IRequest::class ),
			$config,
			new TypePolicy( $config ),
			new Renderers(
				new PopplerRenderer( $this->createMock( CommandRunner::class ), $this->createMock( PageProbe::class ), $vips, $config ),
				$vips,
			),
			$prerequisites,
			$binaries,
			$scratch,
			$loaders,
			$apps,
			$this->markingL10n(),
		)
		    extends
		    SettingsController
		{

//  constructor

			public function __construct(
				private readonly string $body,
				mixed                   ...$arguments,
			)
			{
				parent::__construct( ...$arguments );
			}


//  other non-static methods

			protected function readRequestBody(): string
			{
				return $this->body;
			}
		};
	}
}
