<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Integration;

use OCA\LargeFilePreviews\AppInfo\Application;
use OCA\LargeFilePreviews\Config\ConfigLexicon;
use OCA\LargeFilePreviews\Service\ConfigurationService;
use OCP\Config\ValueType;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * A test against the booted server the app is enabled in: the harness
 * instance, or the one CI installs. Skipped anywhere else.
 *
 * Each test gets a user of its own, removed afterwards with its files, and
 * the app's settings as they ship: what a test sets is cleared again. What
 * the server held before is saved before a class's first test and put back
 * after its last, since the harness instance is one a person uses too.
 */
abstract class ServerTestCase
    extends
    TestCase
{

//  private properties

	private ?IUser $user = null;

	/** @var array<string, mixed>|null each of the app's keys that held a value, with it */
	private static ?array $saved = null;

	/** What system config said of the untrusted decoders. */
	private static mixed $savedUntrusted = null;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		if ( ! class_exists( \OC\Server::class ) || ! interface_exists( IRootFolder::class ) )
		{
			self::markTestSkipped( 'No Nextcloud server here.' );
		}

		$this->resetSettings();
	}


//  other non-static methods

	protected function tearDown(): void
	{
		$this->user?->delete();
		$this->user = null;
		$this->resetSettings();

		parent::tearDown();
	}

	/**
	 * A file in the test user's home, with the content given.
	 */
	protected function file(
		string $name,
		string $content,
	): File
	{
		return $this->home()->newFile( $name, $content );
	}

	protected function home(): Folder
	{
		if ( $this->user === null )
		{
			$uid        = 'lfp-test-' . bin2hex( random_bytes( 4 ) );
			$this->user = Server::get( IUserManager::class )->createUser( $uid, bin2hex( random_bytes( 16 ) ) . 'Aa1!' ) ?: null;
			self::assertNotNull( $this->user, 'Could not create the test user' );
		}

		return Server::get( IRootFolder::class )->getUserFolder( $this->user->getUID() );
	}

	protected function appConfig(): IAppConfig
	{
		return Server::get( IAppConfig::class );
	}

	/**
	 * The service the provider reads its rules through, which a test writes
	 * them through too.
	 */
	protected function settings(): ConfigurationService
	{
		return Server::get( ConfigurationService::class );
	}

	private function resetSettings(): void
	{
		foreach ( ( new ConfigLexicon() )->getAppConfigs() as $entry )
		{
			$this->appConfig()->deleteKey( Application::APP_ID, $entry->getKey() );
		}

		// The one switch a test sets in system config.
		$this->settings()->unsetUntrustedAllowed();
		$this->settings()->forget();
	}


//  static methods

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		if ( ! class_exists( \OC\Server::class ) || ! interface_exists( IRootFolder::class ) )
		{
			return;
		}

		$config      = Server::get( IAppConfig::class );
		self::$saved = [];

		foreach ( ( new ConfigLexicon() )->getAppConfigs() as $entry )
		{
			$key  = $entry->getKey();
			$lazy = $entry->isLazy();

			if ( $config->hasKey( Application::APP_ID, $key, $lazy ) )
			{
				self::$saved[ $key ] = match ( $entry->getValueType() )
				{
					ValueType::BOOL  => $config->getValueBool( Application::APP_ID, $key, false, $lazy ),
					ValueType::INT   => $config->getValueInt( Application::APP_ID, $key, 0, $lazy ),
					ValueType::ARRAY => $config->getValueArray( Application::APP_ID, $key, [], $lazy ),
					default          => $config->getValueString( Application::APP_ID, $key, '', $lazy ),
				};
			}
		}

		self::$savedUntrusted = Server::get( IConfig::class )->getSystemValue( ConfigLexicon::ALLOW_UNTRUSTED_SYSTEM_KEY, null );
	}

	public static function tearDownAfterClass(): void
	{
		if ( self::$saved !== null )
		{
			$config = Server::get( IAppConfig::class );

			foreach ( ( new ConfigLexicon() )->getAppConfigs() as $entry )
			{
				$key  = $entry->getKey();
				$lazy = $entry->isLazy();

				$config->deleteKey( Application::APP_ID, $key );

				if ( ! array_key_exists( $key, self::$saved ) )
				{
					continue;
				}

				$value = self::$saved[ $key ];

				match ( $entry->getValueType() )
				{
					ValueType::BOOL  => $config->setValueBool( Application::APP_ID, $key, (bool) $value, $lazy ),
					ValueType::INT   => $config->setValueInt( Application::APP_ID, $key, (int) $value, $lazy ),
					ValueType::ARRAY => $config->setValueArray( Application::APP_ID, $key, (array) $value, $lazy ),
					default          => $config->setValueString( Application::APP_ID, $key, (string) $value, $lazy ),
				};
			}

			$system = Server::get( IConfig::class );

			self::$savedUntrusted === null
				? $system->deleteSystemValue( ConfigLexicon::ALLOW_UNTRUSTED_SYSTEM_KEY )
				: $system->setSystemValue( ConfigLexicon::ALLOW_UNTRUSTED_SYSTEM_KEY, self::$savedUntrusted );

			Server::get( ConfigurationService::class )->forget();
			self::$saved          = null;
			self::$savedUntrusted = null;
		}

		parent::tearDownAfterClass();
	}
}
