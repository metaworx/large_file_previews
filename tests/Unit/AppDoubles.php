<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Unit;

use Closure;
use mwx\Nextcloud\Testing\EnglishL10n;
use mwx\Nextcloud\Testing\KnownMimeTypes;
use mwx\Nextcloud\Testing\MemoryAppConfig;
use mwx\Nextcloud\Testing\MemoryCache;
use mwx\Nextcloud\Testing\MemorySystemConfig;
use OCA\LargeFilePreviews\Config\SupportedTypes;
use OCA\LargeFilePreviews\Config\TypeRule;
use OCA\LargeFilePreviews\Service\BinaryLocator;
use OCA\LargeFilePreviews\Service\ConfigurationService;
use OCA\LargeFilePreviews\Service\DetectionCache;
use OCP\IBinaryFinder;
use Psr\Log\LoggerInterface;

/**
 * This app's services over the doubles of mwx/nextcloud-testing: an app
 * config in `$this->appValues`, system values in `$this->systemValues`, a
 * cache in `$this->cacheValues`, an English or marking `IL10N`, and a
 * detector that knows the types the tests use. For a class extending
 * PHPUnit's `TestCase`, whose `createMock()` the doubles use.
 */
trait AppDoubles
{
	use EnglishL10n;
	use KnownMimeTypes;
	use MemoryAppConfig;
	use MemoryCache;
	use MemorySystemConfig;


//  other non-static methods

	/**
	 * The settings over `$this->appValues` and `$this->systemValues`, in
	 * English, or with what is translated marked.
	 */
	protected function settings( bool $marking = false ): ConfigurationService
	{
		return new ConfigurationService(
			$this->memoryAppConfig(),
			$marking ? $this->markingL10n() : $this->englishL10n(),
			$this->mimeDetector(),
			$this->systemConfig(),
		);
	}

	/**
	 * PDF and Illustrator switched on, as an administrator does: every type
	 * ships off. Through the service given, or one over the same values.
	 */
	protected function enableDocuments( ?ConfigurationService $service = null ): void
	{
		$service ??= $this->settings();

		foreach ( SupportedTypes::DOCUMENT_TYPES as $mime )
		{
			$service->setMimeRule( $mime, new TypeRule( true ) );
		}
	}

	/**
	 * Programs found as given, a map from name to path or what answers for
	 * a name, overridden as `$this->systemValues` says, and kept in the
	 * memory cache.
	 *
	 * @param array<string, string>|Closure(string): (string|false) $found
	 */
	protected function binaryLocator( array|Closure $found ): BinaryLocator
	{
		$finder = $this->createMock( IBinaryFinder::class );
		$finder->method( 'findBinaryPath' )
		       ->willReturnCallback(
			       $found instanceof Closure
				       ? $found
				       : static fn(
					       string $program,
				       ): string|false => $found[ $program ] ?? false,
		       )
		;

		return new BinaryLocator( $this->systemConfig(), $finder, $this->detectionCache(), $this->createMock( LoggerInterface::class ) );
	}

	/**
	 * The app's detection cache over `$this->cacheValues`.
	 */
	protected function detectionCache(): DetectionCache
	{
		return new DetectionCache( $this->memoryCacheFactory() );
	}
}
