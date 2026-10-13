<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */
if ( ! defined( 'PHPUNIT_RUN' ) )
{
	define( 'PHPUNIT_RUN', 1 );
}

$appRoot = dirname( __DIR__ );

/*
 * A Nextcloud installation this app is deployed into — the app inside
 * apps/<app>/, or the conventional container path. lib/base.php boots the
 * server, so tests get the real OC container and the app's own autoloader
 * comes from the enabled app. This is how CI runs the suite.
 */
foreach ( [ dirname( $appRoot, 2 ), '/var/www/html' ] as $ncRoot )
{
	if ( ! file_exists( $ncRoot . '/3rdparty/autoload.php' ) )
	{
		continue;
	}

	require_once $ncRoot . '/3rdparty/autoload.php';

	if ( file_exists( $ncRoot . '/lib/base.php' ) )
	{
		require_once $ncRoot . '/lib/base.php';
	}

	return;
}

/*
 * Fallback for a working copy that is not inside a server: a Nextcloud source
 * tree checked out beside the app (nextcloud-v33, nextcloud-v34, ...), highest
 * version first, or wherever LFP_NC_ROOT points.
 *
 * Only the autoloaders are loaded, never lib/base.php — an unconfigured source
 * tree cannot boot, and trying leaves a fatal instead of a test report. Tests
 * written against OCP interfaces and mocks run; those that need the global OC
 * class do not.
 */
$candidates = glob( $appRoot . '/nextcloud-v*', GLOB_ONLYDIR ) ?: [];
rsort( $candidates, SORT_NATURAL );

$override = getenv( 'LFP_NC_ROOT' );

if ( is_string( $override ) && $override !== '' )
{
	array_unshift( $candidates, $override );
}

foreach ( $candidates as $ncRoot )
{
	if ( ! file_exists( $ncRoot . '/3rdparty/autoload.php' )
		|| ! file_exists( $ncRoot . '/lib/composer/autoload.php' ) )
	{
		continue;
	}

	require_once $ncRoot . '/3rdparty/autoload.php';
	require_once $ncRoot . '/lib/composer/autoload.php';

	// No server booted to register it, so the app's own autoloader is on us.
	require_once $appRoot . '/vendor/autoload.php';

	return;
}

/*
 * No server at all, as in a plain checkout: the app's own autoloader, and the
 * OCP API from nextcloud/ocp, which declares no autoloading of its own. Enough
 * for unit tests written against OCP interfaces and mocks.
 */
$loader = require $appRoot . '/vendor/autoload.php';
$loader->addPsr4( 'OCP\\', $appRoot . '/vendor/nextcloud/ocp/OCP' );
$loader->addPsr4( 'NCU\\', $appRoot . '/vendor/nextcloud/ocp/NCU' );
