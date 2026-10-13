<?php

declare( strict_types=1 );

/**
 * ECS configuration: the preset published as mwx/coding-standard, over this
 * app's PHP — lib/, tests/, appinfo/ and templates/.
 *
 * @example composer cs:check
 * @example composer cs:fix
 * @example vendor/bin/mwx-ecs check lib/AppInfo/Application.php --fix
 */

use mwx\ECS\Config\ComposedConfig;
use Symplify\EasyCodingStandard\Config\ECSConfig;

$preset = __DIR__ . '/vendor-bin/ecs/vendor/mwx/coding-standard/config/ecs';

$paths = array_filter(
	[
		__DIR__ . '/lib',
		__DIR__ . '/tests',
		__DIR__ . '/appinfo',
		__DIR__ . '/templates',
	],
	is_dir( ... ),
);

return ComposedConfig::fromFiles( $preset . '/default.php' )
                     ->with( ECSConfig::configure()->withPaths( array_values( $paths ) ) )
;
