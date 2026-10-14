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
use OCA\LargeFilePreviews\Service\CommandRunner;
use OCA\LargeFilePreviews\Service\ConfigurationService;
use OCA\LargeFilePreviews\Service\PrerequisitesService;
use OCA\LargeFilePreviews\Service\TypePolicy;
use OCA\LargeFilePreviews\Settings\Admin;
use OCA\LargeFilePreviews\Tests\Unit\EnglishL10n;
use OCA\LargeFilePreviews\Tests\Unit\MemoryAppConfig;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\Files\IMimeTypeDetector;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class SettingsControllerTest
    extends
    TestCase
{
	use EnglishL10n;
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

		self::assertSame( [ 'scalars', 'mimetypes', 'extensions', 'prerequisites', 'ready', 'problems', 'lastFailure', 'overrideKey' ], array_keys( $data ) );
		self::assertSame( 30, $data['scalars']['timeout_seconds']['value'] );
		self::assertSame( 600, $data['scalars']['timeout_seconds']['max'] );
		self::assertCount( 16, $data['mimetypes'] );
		self::assertSame( [ 'application/pdf', true ], [ $data['mimetypes'][0]['mime'], $data['mimetypes'][0]['renderer'] ] );
		self::assertFalse( $data['mimetypes'][2]['renderer'] );
		self::assertSame( 'large_file_previews.binaries', $data['overrideKey'] );
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

	public function testARefusalIsA400WithTheTranslatedReason(): void
	{
		$response = $this->controller( '{"scalars":{"timeout_seconds":900}}' )->update();

		self::assertSame( Http::STATUS_BAD_REQUEST, $response->getStatus() );
		self::assertSame( [ 'error' => '«timeout_seconds must be between 1 and 600.»' ], $response->getData() );
		self::assertSame( [], $this->appValues );
	}

	public function testWhatIsNotARequestIsRefused(): void
	{
		self::assertSame( Http::STATUS_BAD_REQUEST, $this->controller( 'not json' )->update()->getStatus() );
		self::assertSame( Http::STATUS_BAD_REQUEST, $this->controller( '{"mimetypes":{"image/png":"on"}}' )->update()->getStatus() );
		self::assertSame( Http::STATUS_BAD_REQUEST, $this->controller( '{"mimetypes":{"image/png":{"colour":1}}}' )->update()->getStatus() );
	}

	private function controller( string $body ): SettingsController
	{
		$config   = new ConfigurationService( $this->memoryAppConfig(), $this->markingL10n() );
		$detector = $this->createMock( IMimeTypeDetector::class );
		$detector->method( 'detectPath' )
		         ->willReturn( 'image/tiff' )
		;

		$prerequisites = $this->createMock( PrerequisitesService::class );
		$prerequisites->method( 'check' )
		              ->willReturn( [] )
		;

		return new class (
			$body,
			$this->createMock( IRequest::class ),
			$config,
			new TypePolicy( $config ),
			new Renderers( new PopplerRenderer( $this->createMock( CommandRunner::class ), $this->createMock( PageProbe::class ) ) ),
			$prerequisites,
			$detector,
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
