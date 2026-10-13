<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Service;

use OCA\LargeFilePreviews\Config\Resolution;
use OCA\LargeFilePreviews\Config\Source;
use OCA\LargeFilePreviews\Config\SupportedTypes;
use OCA\LargeFilePreviews\Config\TypeRule;
use OCP\Files\FileInfo;

/**
 * Which files the app takes: the app's defaults, then the MIME type's rule,
 * then the extension's, field by field, the most specific value set winning.
 *
 * An extension is the finer level because Nextcloud derives the MIME type
 * mostly from it, so "TIFF, but not .tif" or ".tif only above 200 MB" can
 * be said. Nothing here reads a file: the provider asks for every image
 * preview on the instance, so the answer comes from the MIME type, the
 * name, the size the file cache holds and the configuration, read once per
 * request.
 */
class TypePolicy
{

//  constructor

	public function __construct(
		private readonly ConfigurationService $config,
	)
	{
	}


//  other non-static methods

	public function resolve(
		?string $mime,
		?string $extension,
	): Resolution
	{
		$mime      = $mime === null ? null : strtolower( $mime );
		$extension = $extension === null || $extension === ''
			? null
			: ConfigurationService::normaliseExtension( $extension );

		$enabled       = false;
		$enabledSource = Source::DEFAULT;
		$min           = $this->config->minSizeMb();
		$minSource     = Source::DEFAULT;
		$max           = $this->config->maxSizeMb();
		$maxSource     = Source::DEFAULT;

		$levels = [
			Source::MIME->value      => $mime === null ? null : ( $this->config->mimeRules()[ $mime ] ?? null ),
			Source::EXTENSION->value => $extension === null ? null : ( $this->config->extensionRules()[ $extension ] ?? null ),
		];

		foreach ( $levels as $source => $rule )
		{
			if ( ! $rule instanceof TypeRule )
			{
				continue;
			}

			$source = Source::from( $source );

			if ( $rule->enabled !== null )
			{
				$enabled       = $rule->enabled;
				$enabledSource = $source;
			}

			if ( $rule->minSizeMb !== null )
			{
				$min       = $rule->minSizeMb;
				$minSource = $source;
			}

			if ( $rule->maxSizeMb !== null )
			{
				$max       = $rule->maxSizeMb;
				$maxSource = $source;
			}
		}

		return new Resolution(
			$mime,
			$extension,
			$mime !== null && SupportedTypes::matches( $mime ),
			$enabled,
			$enabledSource,
			$min,
			$minSource,
			$max,
			$maxSource,
		);
	}

	/**
	 * The resolution for a file, from its MIME type and its name.
	 */
	public function resolveFile( FileInfo $file ): Resolution
	{
		return $this->resolve( $file->getMimetype(), self::extensionOf( $file->getName() ) );
	}

	/**
	 * Whether the configuration takes this file; where it is stored is the
	 * provider's question.
	 */
	public function takes( FileInfo $file ): bool
	{
		return $this->resolveFile( $file )
		            ->verdict( (int) $file->getSize() )
		            ->isTaken()
		;
	}


//  static methods

	/**
	 * A name's extension, lower-cased, or null where it has none.
	 */
	public static function extensionOf( string $name ): ?string
	{
		$dot = strrpos( $name, '.' );

		if ( $dot === false || $dot === 0 || $dot === strlen( $name ) - 1 )
		{
			return null;
		}

		return strtolower( substr( $name, $dot + 1 ) );
	}
}
