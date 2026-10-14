<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Unit;

use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * An `IAppConfig` that keeps the app's values in `$this->appValues`, for the
 * methods the app calls: what a test stores there is what the unit reads,
 * and what the unit writes is there to assert.
 *
 * A key that is not set answers with the default the caller passes, as the
 * server does for a key the lexicon declares.
 */
trait MemoryAppConfig
{
	// A ConfigurationService takes a MIME type detector beside the config,
	// and what finds programs a cache.
	use KnownMimeTypes;
	use MemoryCache;
	/** @var array<string, mixed> */
	protected array $appValues = [];


//  other non-static methods

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
		$config->method( 'getValueArray' )
		       ->willReturnCallback(
			       static fn(
				       string $app,
				       string $key,
				       array  $default = [],
			       ): array => (array) $get( $app, $key, $default ),
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
