<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Service;

use Closure;
use OCA\LargeFilePreviews\Preview\LargeFileProvider;
use OCP\IPreview;
use OCP\Preview\IProviderV2;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The preview providers Nextcloud would try for a MIME type, in its order:
 * every provider registered under a regex that matches the type, the
 * regexes longest first and one regex's providers as registered
 * (IPreview::getProviders(), whose order the contract pins, 3.2).
 *
 * Each is named by its class's short name, this app's by the app's name,
 * and marked where it comes before this app's: a provider there is asked
 * first, and may take a file the app would have taken. What is listed is
 * what is registered; whether a provider takes a particular file is known
 * per file only.
 */
final class ProviderChain
{

//  constants

	/** This app's provider, by the app's name. */
	public const OURS = 'Large File Previews';

//  properties

	/**
	 * Each closure's provider class, by the closure's object id; null where
	 * it gives no provider. A closure makes its provider each time it is
	 * called, so it is called once.
	 *
	 * @var array<int, class-string|null>
	 */
	private array $classes = [];


//  constructor

	public function __construct(
		private readonly IPreview        $previews,
		private readonly LoggerInterface $logger,
	)
	{
	}


//  other non-static methods

	/**
	 * The providers for a MIME type, in the order Nextcloud tries them.
	 *
	 * @return list<array{name: string, class: string, ours: bool, before: bool}>
	 */
	public function forMime( string $mime ): array
	{
		$chain = [];
		$ours  = false;

		foreach ( $this->previews->getProviders() as $regex => $closures )
		{
			if ( @preg_match( $regex, $mime ) !== 1 )
			{
				continue;
			}

			foreach ( $closures as $closure )
			{
				$class = $this->classOf( $closure );

				if ( $class === null )
				{
					continue;
				}

				$isOurs  = is_a( $class, LargeFileProvider::class, true );
				$chain[] = [
					'name'   => $isOurs ? self::OURS : self::shortName( $class ),
					'class'  => $class,
					'ours'   => $isOurs,
					'before' => false,
				];
				$ours    = $ours || $isOurs;
			}
		}

		// Before this app's provider: every one listed ahead of it. Where it
		// is not listed at all, nothing comes before it.
		if ( $ours )
		{
			foreach ( $chain as $index => $provider )
			{
				if ( $provider['ours'] )
				{
					break;
				}

				$chain[ $index ]['before'] = true;
			}
		}

		return $chain;
	}

	/**
	 * The class of the provider a closure gives, or null where it gives
	 * none: a provider the server cannot build answers false, which the
	 * preview generator skips too.
	 *
	 * @return class-string|null
	 */
	private function classOf( Closure $closure ): ?string
	{
		$id = spl_object_id( $closure );

		if ( ! array_key_exists( $id, $this->classes ) )
		{
			try
			{
				$provider = $closure();

				$this->classes[ $id ] = $provider instanceof IProviderV2 ? $provider::class : null;
			}
			catch ( Throwable $e )
			{
				$this->logger->info( 'A preview provider could not be built to be named: ' . $e->getMessage(), [ 'exception' => $e ] );

				$this->classes[ $id ] = null;
			}
		}

		return $this->classes[ $id ];
	}


//  static methods

	/** A class's name without its namespace. */
	private static function shortName( string $class ): string
	{
		$cut = strrpos( $class, '\\' );

		return $cut === false ? $class : substr( $class, $cut + 1 );
	}
}
