<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Unit;

use OCA\LargeFilePreviews\Service\DetectionCache;
use OCP\ICache;
use OCP\ICacheFactory;

/**
 * A DetectionCache over a cache held in `$this->cacheValues`, for a test
 * that asserts what was kept, or keeps something to start from.
 */
trait MemoryCache
{
	/** @var array<string, mixed> */
	protected array $cacheValues = [];

	/** @var array<string, int> each key's TTL, as it was last set */
	protected array $cacheTtls = [];


//  other non-static methods

	protected function detectionCache(): DetectionCache
	{
		$values = &$this->cacheValues;
		$ttls   = &$this->cacheTtls;
		$cache  = $this->createMock( ICache::class );

		$cache->method( 'get' )
		      ->willReturnCallback(
			      static function(
				      string $key,
			      ) use (
				      &$values,
			      ): mixed
			      {
				      return $values[ $key ] ?? null;
			      },
		      )
		;
		$cache->method( 'set' )
		      ->willReturnCallback(
			      static function(
				      string $key,
				      mixed  $value,
				      int    $ttl = 0,
			      ) use (
				      &$values,
				      &$ttls,
			      ): bool
			      {
				      $values[ $key ] = $value;
				      $ttls[ $key ]   = $ttl;

				      return true;
			      },
		      )
		;
		$cache->method( 'clear' )
		      ->willReturnCallback(
			      static function() use (
				      &$values,
			      ): bool
			      {
				      $values = [];

				      return true;
			      },
		      )
		;

		$factory = $this->createMock( ICacheFactory::class );
		$factory->method( 'isAvailable' )
		        ->willReturn( true )
		;
		$factory->method( 'createDistributed' )
		        ->willReturn( $cache )
		;

		return new DetectionCache( $factory );
	}
}
