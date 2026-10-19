<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Service;

use OCA\LargeFilePreviews\Renderer\Unsupported;
use OCA\LargeFilePreviews\Renderer\VipsLoaders;
use OCA\LargeFilePreviews\Renderer\VipsRenderer;
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

//  constructor

	public function __construct(
		private readonly ConfigurationService $config,
		private readonly BinaryLocator        $binaries,
		private readonly ProgramVersions      $versions,
		private readonly ITempManager         $tempManager,
		private readonly Platform             $platform,
		private readonly Sandbox              $sandbox,
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
			$flag   = BinaryLocator::PROGRAMS[ $program ]['version'] ?? null;
			$list[] = [
				'name'    => $program,
				'path'    => $location->path,
				'source'  => $location->source,
				'problem' => $location->problem,
				'version' => $location->path !== null && $flag !== null
					? $this->versions->of( $program, $location->path, $flag )
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

		// A file is handed to a program as /dev/fd/<n> (RunOptions).
		$items[] = new Prerequisite(
			Prerequisite::GROUP_PERMISSION,
			'/dev/fd',
			is_dir( '/dev/fd' ) ? Prerequisite::OK : Prerequisite::MISSING,
			'handing the file to a program without its name',
			is_dir( '/dev/fd' ) ? '' : 'no /dev/fd: /proc is not mounted',
		);

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
			'bwrap'   => $limits->bwrapEnabled,
			'images'  => $this->config->imagesEnabled(),
		];
		$items   = [];

		foreach ( BinaryLocator::PROGRAMS as $program => $spec )
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
				$version = $this->versions->of( $program, (string) $location->path, $spec['version'] );
				$detail .= $version === null ? '' : ', ' . $version;

				// libvips renders no image it cannot keep from untrusted decoders.
				if ( $program === VipsRenderer::PROGRAM && ! $this->config->untrustedAllowed() && ! VipsLoaders::blocks( (string) $version ) )
				{
					$detail .= '; ' . Unsupported::UNBLOCKABLE_TEXT;
				}
			}

			// Found is not enough: whether it can start a sandbox here, where
			// it is needed or the versions are asked for. Missing while on.
			$problem = $program === Sandbox::PROGRAM && ( $needed || $versions )
				? $this->sandbox->problem()
				: null;

			$items[] = new Prerequisite(
				Prerequisite::GROUP_PROGRAM,
				$program,
				match ( true )
				{
					$problem === null => Prerequisite::OK,
					$needed           => Prerequisite::MISSING,
					default           => Prerequisite::OPTIONAL,
				},
				$for,
				$problem === null ? $detail : $detail . '; cannot start a sandbox here: ' . $problem,
			);
		}

		return $items;
	}
}
