<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Service;

use OCP\ITempManager;

/**
 * What the app needs from the server, and whether it has it: the PHP
 * functions that start a program, the extensions, and each program.
 *
 * The prerequisites command, the setup check and the settings page all ask
 * here, so they cannot disagree. A limit switched off makes its program
 * optional; switched on, a missing one blocks rendering, since the runner
 * then fails closed.
 */
class PrerequisitesService
{

//  constants

	/**
	 * How each program says which version it is, and which limit needs it.
	 */
	private const PROGRAMS
		 = [
			'pdftoppm'  => [ 'version' => '-v', 'limit' => null, 'for' => 'rendering PDF and Illustrator files' ],
			// Without them a scanned page is enlarged to the full box; no reason to stop rendering.
			'pdfinfo'   => [ 'version' => '-v', 'limit' => false, 'for' => 'rendering a scanned page at its own resolution' ],
			'pdfimages' => [ 'version' => '-v', 'limit' => false, 'for' => 'rendering a scanned page at its own resolution' ],
			// Needed while an image type is switched on, as a limit while it is.
			'vipsthumbnail' => [ 'version' => '--version', 'limit' => 'images', 'for' => 'rendering images' ],
			// Without it an image smaller than the preview is enlarged.
			'vipsheader'    => [ 'version' => '--version', 'limit' => false, 'for' => 'rendering an image at its own size where that is smaller' ],
			// Without it, which image types libvips reads is found out by
			// rendering them, and a PDF previewed as WebP is a JPEG.
			'vips'          => [ 'version' => '--version', 'limit' => false, 'for' => 'telling which image types libvips reads, and WebP previews of PDFs' ],
			// Without it, a TIFF in one large strip that runs out of memory stays a failure.
			'tiffcp'        => [ 'version' => '-h', 'limit' => false, 'for' => 'a second chance for a TIFF in one strip that runs out of memory' ],
			'timeout'       => [ 'version' => '--version', 'limit' => 'timeout', 'for' => 'the timeout of each render' ],
			'prlimit'       => [ 'version' => '--version', 'limit' => 'prlimit', 'for' => 'the address-space cap of each render' ],
			'choom'         => [ 'version' => '--version', 'limit' => 'choom', 'for' => 'the OOM score of each render' ],
		];


//  constructor

	public function __construct(
		private readonly ConfigurationService $config,
		private readonly BinaryLocator        $binaries,
		private readonly CommandRunner        $runner,
		private readonly ITempManager         $tempManager,
		private readonly DetectionCache       $cache,
		private readonly Platform             $platform,
	)
	{
	}


//  other non-static methods

	/**
	 * Every prerequisite, permissions first.
	 *
	 * @param bool $versions whether to run each program found for its
	 *                       version; the setup check does not, the command does
	 * @return list<Prerequisite>
	 */
	public function check( bool $versions = false ): array
	{
		return [
			...$this->permissions(),
			...$this->extensions(),
			...$this->programs( $versions ),
		];
	}

	/**
	 * Whether anything the configuration needs is missing.
	 *
	 * @param list<Prerequisite>|null $items from check(), or null to check now
	 */
	public function blocking( ?array $items = null ): bool
	{
		foreach ( $items ?? $this->check() as $item )
		{
			if ( $item->isBlocking() )
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Every program the app runs: where it is, how it was found, and its
	 * version, from the same cache as check()'s.
	 *
	 * @return list<array{name: string, path: ?string, source: string, problem: ?string, version: ?string, install: array{command: ?string, note: ?string}|null}>
	 */
	public function programList(): array
	{
		$list = [];

		foreach ( $this->binaries->locateAll() as $program => $location )
		{
			$flag   = self::PROGRAMS[ $program ]['version'] ?? null;
			$list[] = [
				'name'    => $program,
				'path'    => $location->path,
				'source'  => $location->source,
				'problem' => $location->problem,
				'version' => $location->path !== null && $flag !== null
					? $this->version( $program, $location->path, $flag )
					: null,
				'install' => $this->platform->install( $program ),
			];
		}

		return $list;
	}

	/**
	 * The platform's name, where it is one the app knows packages for.
	 */
	public function platformName(): ?string
	{
		return $this->platform->name();
	}

	/**
	 * The platform's family (Platform::DEBIAN and so on), for the page to
	 * name in its reader's language.
	 */
	public function platformFamily(): ?string
	{
		return $this->platform->family();
	}

	/**
	 * @return list<Prerequisite>
	 */
	private function permissions(): array
	{
		$items = [];

		foreach ( [ 'proc_open', 'proc_get_status', 'proc_close' ] as $function )
		{
			$items[] = new Prerequisite(
				Prerequisite::GROUP_PERMISSION,
				$function,
				function_exists( $function ) ? Prerequisite::OK : Prerequisite::DISABLED,
				'starting the renderer',
				function_exists( $function ) ? '' : 'listed in disable_functions',
			);
		}

		$temp    = (string) $this->tempManager->getTempBaseDir();
		$items[] = new Prerequisite(
			Prerequisite::GROUP_PERMISSION,
			'temporary directory',
			$temp !== '' && is_dir( $temp ) && is_writable( $temp ) ? Prerequisite::OK : Prerequisite::MISSING,
			'the rendered image and the renderer\'s messages',
			$temp,
		);

		return $items;
	}

	/**
	 * @return list<Prerequisite>
	 */
	private function extensions(): array
	{
		return [
			new Prerequisite(
				Prerequisite::GROUP_EXTENSION,
				'gd',
				extension_loaded( 'gd' ) ? Prerequisite::OK : Prerequisite::MISSING,
				'loading the rendered image',
			),
			new Prerequisite(
				Prerequisite::GROUP_EXTENSION,
				'sysvsem',
				extension_loaded( 'sysvsem' ) ? Prerequisite::OK : Prerequisite::OPTIONAL,
				'Nextcloud\'s limit on concurrent previews (preview_concurrency_new)',
			),
		];
	}

	/**
	 * @return list<Prerequisite>
	 */
	private function programs( bool $versions ): array
	{
		$limits  = $this->config->limits();
		$enabled = [
			'timeout' => $limits->timeoutEnabled,
			'prlimit' => $limits->prlimitEnabled,
			'choom'   => $limits->choomEnabled,
			'images'  => $this->config->imagesEnabled(),
		];
		$items   = [];

		foreach ( self::PROGRAMS as $program => $spec )
		{
			$location = $this->binaries->locate( $program );
			// null: always needed; false: never; a limit's name: while it is on.
			$needed = match ( true )
			{
				$spec['limit'] === null  => true,
				$spec['limit'] === false => false,
				default                  => $enabled[ $spec['limit'] ],
			};
			$for    = $spec['for'] . ( is_string( $spec['limit'] ) && ! $needed ? ', switched off' : '' );

			if ( ! $location->isFound() )
			{
				$items[] = new Prerequisite(
					Prerequisite::GROUP_PROGRAM,
					$program,
					$needed ? Prerequisite::MISSING : Prerequisite::OPTIONAL,
					$for,
					$location->problem ?? '',
					$this->platform->install( $program ),
				);

				continue;
			}

			$detail = sprintf( '%s (%s)', (string) $location->path, $location->source );

			if ( $versions )
			{
				$version = $this->version( $program, (string) $location->path, $spec['version'] );
				$detail .= $version === null ? '' : ', ' . $version;
			}

			$items[] = new Prerequisite(
				Prerequisite::GROUP_PROGRAM,
				$program,
				Prerequisite::OK,
				$for,
				$detail,
			);
		}

		return $items;
	}

	/**
	 * The first line a program prints when asked for its version.
	 */
	private function version(
		string $program,
		string $path,
		string $flag,
	): ?string
	{
		// Kept by path: another binary in its place is another version.
		$cached = $this->cache->get( 'version.' . $path );

		if ( $cached !== null )
		{
			return $cached === '' ? null : $cached;
		}

		$outcome = $this->runner->runBare( $program, [ $flag ] );
		$line    = trim( (string) strtok( $outcome->stderr, "\n" ) );
		$this->cache->set( 'version.' . $path, $line );

		return $line === '' ? null : $line;
	}
}
