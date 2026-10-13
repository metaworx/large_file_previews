<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\AppInfo;

use OCA\LargeFilePreviews\Config\ConfigLexicon;
use OCA\LargeFilePreviews\Config\SupportedTypes;
use OCA\LargeFilePreviews\Preview\LargeFileProvider;
use OCA\LargeFilePreviews\SetupCheck\RendererCheck;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application
    extends
    App
    implements
    IBootstrap
{

//  constants

	public const APP_ID = 'large_file_previews';


//  constructor

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct()
	{
		parent::__construct( self::APP_ID );
	}


//  other non-static methods

	#[\Override]
	public function register( IRegistrationContext $context ): void
	{
		$context->registerConfigLexicon( ConfigLexicon::class );
		$context->registerPreviewProvider( LargeFileProvider::class, SupportedTypes::MIME_REGEX );
		$context->registerSetupCheck( RendererCheck::class );
	}

	#[\Override]
	public function boot( IBootContext $context ): void
	{
	}
}
