<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Settings;

use OCA\LargeFilePreviews\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

/**
 * The admin settings page, in Nextcloud's "Additional settings" section:
 * one page needs no section of its own.
 *
 * @noinspection PhpUnused
 */
class Admin
    implements
    ISettings
{

//  getters / setters / is* / has*

	#[\Override]
	public function getForm(): TemplateResponse
	{
		return new TemplateResponse( Application::APP_ID, 'settings-admin', [], '' );
	}

	#[\Override]
	public function getSection(): string
	{
		return 'additional';
	}

	#[\Override]
	public function getPriority(): int
	{
		return 50;
	}
}
