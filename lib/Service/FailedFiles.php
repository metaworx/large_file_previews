<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Service;

use OCA\LargeFilePreviews\Config\ConfigLexicon;
use OCP\Files\FileInfo;

/**
 * The files that failed to render, left to Nextcloud's other providers for
 * failure_retry_minutes, rather than failing again on every page that
 * shows them.
 *
 * Kept in DetectionCache, by the file's id and etag and by the settings'
 * fingerprint (ConfigurationService::fingerprint()): a file changed, a
 * setting changed, or the programs found again, and the file is tried at
 * once. Only a failure that is the file's is kept (Outcome::concernsTheFile());
 * where there is no memory cache, nothing is, and every request tries again.
 */
class FailedFiles
{

//  constructor

	public function __construct(
		private readonly DetectionCache       $cache,
		private readonly ConfigurationService $config,
	)
	{
	}


//  other non-static methods

	public function remember(
		FileInfo      $file,
		RenderOutcome $outcome,
	): void
	{
		$minutes = $this->minutes();

		if ( $minutes === 0 || ! $outcome->outcome->concernsTheFile() )
		{
			return;
		}

		$this->cache->set( $this->key( $file ), $outcome->outcome->value, $minutes * 60 );
	}

	/**
	 * Whether the file failed within failure_retry_minutes, under the
	 * settings there are now.
	 */
	public function remembers( FileInfo $file ): bool
	{
		return $this->minutes() > 0 && $this->cache->get( $this->key( $file ) ) !== null;
	}

	private function minutes(): int
	{
		return (int) $this->config->getScalar( ConfigLexicon::FAILURE_RETRY_MINUTES );
	}

	private function key( FileInfo $file ): string
	{
		return sprintf( 'failed.%d.%s', (int) $file->getId(), md5( $file->getEtag() . "\n" . $this->config->fingerprint() ) );
	}
}
