<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Renderer;

use OCA\LargeFilePreviews\Service\BinaryLocator;
use OCA\LargeFilePreviews\Service\CommandRunner;
use OCA\LargeFilePreviews\Service\DetectionCache;
use OCA\LargeFilePreviews\Service\ProgramVersions;

/**
 * The loaders this server's libvips has, and which of them it counts as
 * untrusted, from `vips -l foreign`:
 *
 *     VipsForeignLoadJp2kFile (jp2kload), load JPEG2000 image (.j2k, ...), priority=0, untrusted, is_a, ...
 *
 * Asked once and kept in DetectionCache, by the path of `vips`: another
 * binary there may have other loaders. Refreshed with the paths. A listing
 * that could not be read is kept as unknown, for this request and for
 * UNKNOWN_TTL seconds.
 *
 * And whether it can block those at all: VIPS_BLOCK_UNTRUSTED and the
 * untrusted flag are libvips 8.13's. Before it nothing is blocked, and
 * libvips picks its decoder by the content, not by the type.
 */
class VipsLoaders
{

//  constants

	public const PROGRAM = 'vips';

	/** The first libvips that blocks untrusted decoders. */
	public const BLOCKS_FROM = '8.13';

	/**
	 * libvips 8.15 lists its foreign classes in about 16 KB.
	 */
	private const LISTING_LIMIT = 262_144;

	/**
	 * How long a listing that could not be read is not asked for again:
	 * long enough that a folder of images does not start `vips` for each,
	 * short enough that a repaired libvips is soon seen.
	 */
	public const UNKNOWN_TTL = 60;

	/** @var array<string, bool>|null */
	private ?array $loaders = null;


//  private properties

	/** Whether `vips` was asked in this request, and could not tell. */
	private bool $unknown = false;


//  constructor

	public function __construct(
		private readonly CommandRunner   $runner,
		private readonly BinaryLocator   $binaries,
		private readonly DetectionCache  $cache,
		private readonly ProgramVersions $versions,
	)
	{
	}


//  other non-static methods

	/**
	 * Each loader's name, and whether it is untrusted; null where `vips`
	 * is not there or did not answer, so that nothing can be told.
	 *
	 * @return array<string, bool>|null
	 */
	public function loaders(): ?array
	{
		if ( $this->loaders !== null || $this->unknown )
		{
			return $this->loaders;
		}

		$location = $this->binaries->locate( self::PROGRAM );

		if ( ! $location->isFound() )
		{
			return null;
		}

		$key    = 'vips-loaders.' . (string) $location->path;
		$cached = $this->cache->get( $key );

		// An empty string: asked a moment ago, and it could not tell.
		if ( $cached === '' )
		{
			$this->unknown = true;

			return null;
		}

		if ( $cached !== null )
		{
			$decoded = json_decode( $cached, true );

			if ( is_array( $decoded ) )
			{
				/** @var array<string, bool> $decoded */
				return $this->loaders = $decoded;
			}
		}

		$outcome = $this->runner->runBare( self::PROGRAM, [ '-l', 'foreign' ], self::LISTING_LIMIT );
		$loaders = $outcome->isSuccess() ? self::parse( $outcome->stderr ) : [];

		// Kept for a minute only when it did not answer: not asked for every
		// image of a folder, and asked again soon.
		if ( $loaders === [] )
		{
			$this->unknown = true;
			$this->cache->set( $key, '', self::UNKNOWN_TTL );

			return null;
		}

		$this->cache->set( $key, (string) json_encode( $loaders ) );

		return $this->loaders = $loaders;
	}

	/**
	 * Whether this libvips can block its untrusted decoders, by the version
	 * vipsthumbnail, which renders, reports; null where it is not there. A
	 * version it does not say counts as one that cannot.
	 */
	public function canBlockUntrusted(): ?bool
	{
		$location = $this->binaries->locate( VipsRenderer::PROGRAM );

		if ( ! $location->isFound() )
		{
			return null;
		}

		return self::blocks( (string) $this->versions->of( VipsRenderer::PROGRAM, (string) $location->path, '--version' ) );
	}


//  static methods

	/**
	 * Whether the version a libvips program prints, `vips-8.15.1`, is one
	 * that blocks untrusted decoders.
	 */
	public static function blocks( string $version ): bool
	{
		return preg_match( '/^vips-(\d+\.\d+)/', $version, $matches ) === 1
			&& version_compare( $matches[1], self::BLOCKS_FROM, '>=' );
	}

	/**
	 * @return array<string, bool>
	 */
	public static function parse( string $listing ): array
	{
		preg_match_all( '/\((\w+load)\), [^\n]*?priority=-?\d+(, untrusted)?/', $listing, $matches, PREG_SET_ORDER );

		$loaders = [];

		foreach ( $matches as $match )
		{
			$loaders[ $match[1] ] = ( $match[2] ?? '' ) !== '';
		}

		return $loaders;
	}
}
