<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Migration;

use OCA\LargeFilePreviews\Service\BinaryLocator;
use OCA\LargeFilePreviews\Service\Prerequisite;
use OCA\LargeFilePreviews\Service\PrerequisitesService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Finds the server's programs again, on install and after each upgrade:
 * an upgrade of the server is when programs come and go, and what the
 * cache held from before may no longer be true.
 *
 * Reports what it found; never fails the upgrade over it.
 *
 * @noinspection PhpUnused
 */
class RefreshDetection
    implements
    IRepairStep
{

//  constructor

	public function __construct(
		private readonly BinaryLocator        $binaries,
		private readonly PrerequisitesService $prerequisites,
	)
	{
	}


//  getters / setters / is* / has*

	#[\Override]
	public function getName(): string
	{
		return 'Find the programs Large File Previews runs, and their versions';
	}


//  config/init/exe/run methods

	#[\Override]
	public function run( IOutput $output ): void
	{
		$this->binaries->refresh();

		foreach ( $this->prerequisites->check( true ) as $item )
		{
			if ( $item->group !== Prerequisite::GROUP_PROGRAM )
			{
				continue;
			}

			$output->info( sprintf( '%s: %s%s', $item->name, $item->status, $item->detail === '' ? '' : ' (' . $item->detail . ')' ) );

			if ( $item->isBlocking() )
			{
				$output->warning( sprintf( '%s is missing: %s', $item->name, $item->neededFor ) );
			}
		}
	}
}
