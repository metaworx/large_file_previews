<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Service;

/**
 * Which version each program is: the first line it prints when asked.
 *
 * Kept in DetectionCache by the program's path, since another binary in its
 * place is another version, and found again with the paths. The page, the
 * command and the renderers ask here, so they cannot disagree.
 */
class ProgramVersions
{

//  constructor

	public function __construct(
		private readonly CommandRunner  $runner,
		private readonly DetectionCache $cache,
	)
	{
	}


//  other non-static methods

	/**
	 * @param string $path the program's path, as BinaryLocator found it
	 * @param string $flag what makes it print its version, `--version` mostly
	 */
	public function of(
		string $program,
		string $path,
		string $flag,
	): ?string
	{
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
