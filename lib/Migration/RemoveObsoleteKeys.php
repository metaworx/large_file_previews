<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Migration;

use OCA\LargeFilePreviews\AppInfo\Application;
use OCA\LargeFilePreviews\Config\ConfigLexicon;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Removes the app-config keys the app no longer declares
 * (ConfigLexicon::OBSOLETE), on install and after each upgrade, so that no
 * value is left that looks as if it still meant something.
 *
 * Asks for the stored keys first: deleting a key the lexicon does not
 * declare logs a warning, which only a key that is there is worth.
 *
 * @noinspection PhpUnused
 */
class RemoveObsoleteKeys
    implements
    IRepairStep
{

//  constructor

	public function __construct(
		private readonly IAppConfig $appConfig,
	)
	{
	}


//  getters / setters / is* / has*

	#[\Override]
	public function getName(): string
	{
		return 'Remove the settings Large File Previews no longer has';
	}


//  config/init/exe/run methods

	#[\Override]
	public function run( IOutput $output ): void
	{
		$stored = $this->appConfig->getKeys( Application::APP_ID );

		foreach ( array_intersect( ConfigLexicon::OBSOLETE, $stored ) as $key )
		{
			$this->appConfig->deleteKey( Application::APP_ID, $key );
			$output->info( sprintf( 'Removed the obsolete setting %s', $key ) );
		}
	}
}
