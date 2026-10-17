<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Unit;

use Closure;
use OCA\LargeFilePreviews\Config\SupportedTypes;
use OCA\LargeFilePreviews\Config\TypeRule;
use OCA\LargeFilePreviews\Service\BinaryLocator;
use OCA\LargeFilePreviews\Service\ConfigurationService;
use OCP\IAppConfig;
use OCP\IBinaryFinder;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * An `IAppConfig` that keeps the app's values in `$this->appValues`, for the
 * methods the app calls: what a test stores there is what the unit reads,
 * and what the unit writes is there to assert.
 *
 * A key that is not set answers with the default the caller passes, as the
 * server does for a key the lexicon declares. System config, which the
 * service reads for Nextcloud's own preview limits, is `$this->systemValues`.
 *
 * A ConfigurationService and a BinaryLocator over both come ready made
 * (settings(), binaryLocator()).
 */
trait MemoryAppConfig
{
	// A ConfigurationService takes a translator and a MIME type detector
	// beside the config, and what finds programs a cache.
	use EnglishL10n;
	use KnownMimeTypes;
	use MemoryCache;
	/** @var array<string, mixed> */
	protected array $appValues = [];

	/** @var array<string, mixed> */
	protected array $systemValues = [];


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

	protected function systemConfig(): IConfig&MockObject
	{
		$config = $this->createMock( IConfig::class );
		$values = &$this->systemValues;

		$config->method( 'getSystemValue' )
		       ->willReturnCallback(
			       static function(
				       string $key,
				       mixed  $default = '',
			       ) use (
				       &$values,
			       ): mixed
			       {
				       return $values[ $key ] ?? $default;
			       },
		       )
		;
		$config->method( 'getSystemValueInt' )
		       ->willReturnCallback(
			       static function(
				       string $key,
				       int    $default = 0,
			       ) use (
				       &$values,
			       ): int
			       {
				       return (int) ( $values[ $key ] ?? $default );
			       },
		       )
		;
		$config->method( 'getSystemValueString' )
		       ->willReturnCallback(
			       static function(
				       string $key,
				       string $default = '',
			       ) use (
				       &$values,
			       ): string
			       {
				       return (string) ( $values[ $key ] ?? $default );
			       },
		       )
		;
		$config->method( 'setSystemValue' )
		       ->willReturnCallback(
			       static function(
				       string $key,
				       mixed  $value,
			       ) use (
				       &$values,
			       ): void
			       {
				       $values[ $key ] = $value;
			       },
		       )
		;
		$config->method( 'deleteSystemValue' )
		       ->willReturnCallback(
			       static function(
				       string $key,
			       ) use (
				       &$values,
			       ): void
			       {
				       unset( $values[ $key ] );
			       },
		       )
		;

		return $config;
	}

	protected function memoryAppConfig(): IAppConfig&MockObject
	{
		$config = $this->createMock( IAppConfig::class );
		$values = &$this->appValues;

		$config->method( 'hasKey' )
		       ->willReturnCallback(
			       static function(
				       string $app,
				       string $key,
			       ) use (
				       &$values,
			       ): bool
			       {
				       return array_key_exists( $key, $values );
			       },
		       )
		;

		$get = static function(
			string $app,
			string $key,
			mixed  $default = null,
		) use (
			&$values,
		): mixed
		{
			return $values[ $key ] ?? $default;
		};

		$config->method( 'getValueInt' )
		       ->willReturnCallback( $get )
		;
		$config->method( 'getValueBool' )
		       ->willReturnCallback( $get )
		;
		$config->method( 'getValueString' )
		       ->willReturnCallback( $get )
		;
		$config->method( 'getValueArray' )
		       ->willReturnCallback(
			       static fn(
				       string $app,
				       string $key,
				       array  $default = [],
			       ): array => (array) $get( $app, $key, $default ),
		       )
		;
		// The type a value is stored as, from the PHP type a test gives it.
		$config->method( 'getValueType' )
		       ->willReturnCallback(
			       static function(
				       string $app,
				       string $key,
			       ) use (
				       &$values,
			       ): int
			       {
				       return match ( true )
				       {
					       is_int( $values[ $key ] ?? null )    => IAppConfig::VALUE_INT,
					       is_bool( $values[ $key ] ?? null )   => IAppConfig::VALUE_BOOL,
					       is_array( $values[ $key ] ?? null )  => IAppConfig::VALUE_ARRAY,
					       is_string( $values[ $key ] ?? null ) => IAppConfig::VALUE_STRING,
					       default                              => IAppConfig::VALUE_MIXED,
				       };
			       },
		       )
		;

		$set = static function(
			string $app,
			string $key,
			mixed  $value,
		) use (
			&$values,
		): bool
		{
			$values[ $key ] = $value;

			return true;
		};

		$config->method( 'setValueInt' )
		       ->willReturnCallback( $set )
		;
		$config->method( 'setValueBool' )
		       ->willReturnCallback( $set )
		;
		$config->method( 'setValueString' )
		       ->willReturnCallback( $set )
		;
		$config->method( 'setValueArray' )
		       ->willReturnCallback( $set )
		;

		$config->method( 'deleteKey' )
		       ->willReturnCallback(
			       static function(
				       string $app,
				       string $key,
			       ) use (
				       &$values,
			       ): void
			       {
				       unset( $values[ $key ] );
			       },
		       )
		;

		return $config;
	}
}
