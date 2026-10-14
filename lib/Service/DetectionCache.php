<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Service;

use OCA\LargeFilePreviews\AppInfo\Application;
use OCP\ICache;
use OCP\ICacheFactory;

/**
 * What was found out about the server's programs - their paths, their
 * versions - kept so that it is not found out on every request; and which
 * files failed to render with them (FailedFiles), forgotten with the rest
 * when the programs are found again.
 *
 * Nextcloud's memory cache, never app config: app config can be written over
 * the provisioning API, and a path an administrator without shell access
 * could place there would be code the web server runs. The distributed cache
 * where there is one, so that every web server agrees; the local one
 * otherwise; nothing where neither is configured, and the answer is then
 * found out each time.
 */
class DetectionCache
{

//  constants

	/** A day: programs are installed and removed by hand, and rarely. */
	public const TTL = 86_400;


//  private properties

	private ?ICache $cache = null;


//  constructor

	public function __construct(
		private readonly ICacheFactory $factory,
	)
	{
	}


//  other non-static methods

	public function get( string $key ): ?string
	{
		$value = $this->cache()->get( $key );

		return is_string( $value ) ? $value : null;
	}

	/**
	 * @param int $ttl seconds it is kept
	 */
	public function set(
		string $key,
		string $value,
		int    $ttl = self::TTL,
	): void
	{
		$this->cache()->set( $key, $value, $ttl );
	}

	/**
	 * Forgets everything, so the next question finds it out again.
	 */
	public function clear(): void
	{
		$this->cache()->clear();
	}

	private function cache(): ICache
	{
		return $this->cache ??= $this->factory->isAvailable()
			? $this->factory->createDistributed( Application::APP_ID . '-detection' )
			: $this->factory->createLocal( Application::APP_ID . '-detection' );
	}
}
