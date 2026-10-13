<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

use OCA\LargeFilePreviews\AppInfo\Application;
use OCP\Util;

Util::addScript( Application::APP_ID, Application::APP_ID . '-settings-admin' );
Util::addStyle( Application::APP_ID, Application::APP_ID . '-settings-admin' );
?>

<div id="lfp-admin-settings"></div>
