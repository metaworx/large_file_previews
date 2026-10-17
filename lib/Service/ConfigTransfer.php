<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Service;

use DateTimeInterface;
use DateTimeZone;
use JsonException;
use OCA\LargeFilePreviews\AppInfo\Application;
use OCA\LargeFilePreviews\Config\ConfigLexicon;
use OCA\LargeFilePreviews\Config\ConfigurationException;
use OCA\LargeFilePreviews\Config\RuleChange;
use OCA\LargeFilePreviews\Config\SupportedTypes;
use OCA\LargeFilePreviews\Config\Transfer;
use OCA\LargeFilePreviews\Config\TransferChange;
use OCA\LargeFilePreviews\Config\TransferWarning;
use OCA\LargeFilePreviews\Config\TypeRule;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use stdClass;

/**
 * The configuration as a file, and a file as the configuration: a backup
 * of what is stored, and its restore.
 *
 * Only what is stored is exported, so that a restore does not freeze the
 * defaults of the version that wrote it. A file is read leniently and
 * applied strictly: what this app does not know is left out and named
 * (TransferWarning), while a file it cannot read, or a value it knows and
 * that is not valid, refuses the whole of it. Applying is one
 * ConfigurationService::apply(), all or nothing, after which the system
 * values follow - from occ only, each checked before anything is written.
 *
 * Merged, an import changes what the file names; replacing, it also
 * returns everything else to its default.
 */
final class ConfigTransfer
{

//  constants

	/** The newest format this app reads, and the one it writes. */
	public const FORMAT = 1;

	public const BINARIES = 'binaries';

	public const UNTRUSTED = 'vips_allow_untrusted';

	public const SCRATCH = 'scratch_directory';

	private const PARTS = [ 'app', 'format', 'exported', 'settings', 'mimetypes', 'extensions', 'system' ];


//  constructor

	public function __construct(
		private readonly ConfigurationService $config,
		private readonly BinaryLocator        $binaries,
		private readonly ScratchDirectory     $scratch,
		private readonly IAppManager          $apps,
		private readonly ITimeFactory         $time,
		private readonly IL10N                $l10n,
	)
	{
	}


//  other non-static methods

	/**
	 * What is stored, as a file's content.
	 *
	 * @return array<string, mixed>
	 */
	public function export(): array
	{
		$settings = [];

		foreach ( array_keys( ConfigLexicon::SCALARS ) as $key )
		{
			if ( $this->config->isStored( $key ) )
			{
				$settings[ $key ] = $this->config->getScalar( $key );
			}
		}

		$system = [];

		if ( $this->binaries->overrides() !== [] )
		{
			$system[ self::BINARIES ] = $this->binaries->overrides();
		}

		if ( $this->config->untrustedStored() !== null )
		{
			$system[ self::UNTRUSTED ] = $this->config->untrustedStored();
		}

		if ( $this->scratch->override() !== null )
		{
			$system[ self::SCRATCH ] = $this->scratch->override();
		}

		return [
			'app'        => Application::APP_ID,
			'format'     => self::FORMAT,
			'exported'   => [
				'version' => $this->apps->getAppVersion( Application::APP_ID ),
				'date'    => $this->time->getDateTime( 'now', new DateTimeZone( 'UTC' ) )->format( DateTimeInterface::ATOM ),
			],
			'settings'   => self::object( $settings ),
			'mimetypes'  => self::object( self::rules( $this->config->storedMimeRules() ) ),
			'extensions' => self::object( self::rules( $this->config->extensionRules() ) ),
			'system'     => self::object( $system ),
		];
	}

	/**
	 * A file's content, read: what this app knows, each value checked on
	 * its own, and what it left out.
	 *
	 * @throws ConfigurationException where the file cannot be read, or a value it knows is not valid
	 */
	public function read( string $text ): Transfer
	{
		try
		{
			$document = json_decode( $text, true, 32, JSON_THROW_ON_ERROR );
		}
		catch ( JsonException )
		{
			throw $this->refusal( 'The file is not JSON.', $this->l10n->t( 'The file is not JSON.' ) );
		}

		if ( ! is_array( $document ) || ( $document !== [] && array_is_list( $document ) ) || ( $document['app'] ?? null ) !== Application::APP_ID )
		{
			throw $this->refusal(
				'The file is not a configuration of this app.',
				$this->l10n->t( 'The file is not a configuration of this app.' ),
			);
		}

		$format = $document['format'] ?? null;

		if ( ! is_int( $format ) || $format < 1 )
		{
			throw $this->refusal( 'The file names no format.', $this->l10n->t( 'The file names no format.' ) );
		}

		if ( $format > self::FORMAT )
		{
			throw $this->refusal(
				sprintf( 'The file is of format %d; this app reads format %d and older.', $format, self::FORMAT ),
				$this->l10n->t( 'The file is of format %1$d; this app reads format %2$d and older.', [ $format, self::FORMAT ] ),
			);
		}

		$warnings = [];

		foreach ( array_diff( self::keys( $document ), self::PARTS ) as $part )
		{
			$warnings[] = new TransferWarning( TransferWarning::PART, $part );
		}

		$exported = is_array( $document['exported'] ?? null ) ? $document['exported'] : [];
		$settings = $this->settings( $this->part( $document, 'settings' ), $warnings );
		$mimes    = $this->mimeRules( $this->part( $document, 'mimetypes' ), $warnings );
		$exts     = $this->extensionRules( $this->part( $document, 'extensions' ), $warnings );
		$system   = $this->part( $document, 'system' );

		$binaries  = [];
		$untrusted = null;
		$scratch   = null;

		foreach ( $system as $key => $value )
		{
			$key = (string) $key;

			switch ( $key )
			{
				case self::BINARIES:
					$binaries = $this->paths( $value, $warnings );
					break;

				case self::UNTRUSTED:
					if ( ! is_bool( $value ) )
					{
						throw $this->refusal(
							sprintf( '%s is not true or false.', $key ),
							$this->l10n->t( '%s is not true or false.', [ $key ] ),
						);
					}

					$untrusted = $value;
					break;

				case self::SCRATCH:
					if ( ! is_string( $value ) || $value === '' )
					{
						throw $this->refusal(
							sprintf( '%s is not a path.', $key ),
							$this->l10n->t( '%s is not a path.', [ $key ] ),
						);
					}

					$scratch = $value;
					break;

				default:
					$warnings[] = new TransferWarning( TransferWarning::SYSTEM, $key );
			}
		}

		return new Transfer(
			$settings,
			$mimes,
			$exts,
			$binaries,
			$untrusted,
			$scratch,
			is_string( $exported['version'] ?? null ) ? $exported['version'] : null,
			is_string( $exported['date'] ?? null ) ? $exported['date'] : null,
			$warnings,
		);
	}

	/**
	 * What an import would change, from what is stored to what would be,
	 * in the order of settings, MIME types, extensions and system values.
	 *
	 * @return list<TransferChange>
	 */
	public function changes(
		Transfer $transfer,
		bool     $replace,
	): array
	{
		$changes = [];

		foreach ( array_keys( ConfigLexicon::SCALARS ) as $key )
		{
			$before = $this->config->isStored( $key ) ? $this->config->getScalar( $key ) : null;
			$after  = array_key_exists( $key, $transfer->settings ) ? $transfer->settings[ $key ] : ( $replace ? null : $before );

			if ( $before !== $after )
			{
				$changes[] = new TransferChange( TransferChange::SETTINGS, $key, $before, $after );
			}
		}

		foreach ( [
			TransferChange::MIMETYPES  => [ $this->config->storedMimeRules(), $transfer->mimetypes ],
			TransferChange::EXTENSIONS => [ $this->config->extensionRules(), $transfer->extensions ],
		] as $part => [ $stored, $wanted ] )
		{
			$names = array_unique( [ ...array_keys( $stored ), ...array_keys( $wanted ) ] );
			sort( $names );

			foreach ( $names as $name )
			{
				$name   = (string) $name;
				$before = self::stored( $stored[ $name ] ?? null );
				$after  = array_key_exists( $name, $wanted ) ? self::stored( $wanted[ $name ] ) : ( $replace ? null : $before );

				if ( $before !== $after )
				{
					$changes[] = new TransferChange( $part, $name, $before, $after );
				}
			}
		}

		$overrides = $this->binaries->overrides();
		$programs  = array_unique( [ ...array_keys( $overrides ), ...array_keys( $transfer->binaries ) ] );
		sort( $programs );

		foreach ( $programs as $program )
		{
			$before = $overrides[ $program ] ?? null;
			$after  = $transfer->binaries[ $program ] ?? ( $replace ? null : $before );

			if ( $before !== $after )
			{
				$changes[] = new TransferChange( TransferChange::SYSTEM, self::BINARIES . '.' . $program, $before, $after );
			}
		}

		foreach ( [
			self::UNTRUSTED => [ $this->config->untrustedStored(), $transfer->untrusted ],
			self::SCRATCH   => [ $this->scratch->override(), $transfer->scratch ],
		] as $key => [ $before, $wanted ] )
		{
			$after = $wanted ?? ( $replace ? null : $before );

			if ( $before !== $after )
			{
				$changes[] = new TransferChange( TransferChange::SYSTEM, $key, $before, $after );
			}
		}

		return $changes;
	}

	/**
	 * The changes an import would make, refused where one would be: every
	 * setting and rule as ConfigurationService::apply() checks it, and,
	 * where the system values are to be applied, each path as occ checks it.
	 *
	 * @return list<TransferChange>
	 * @throws ConfigurationException
	 */
	public function check(
		Transfer $transfer,
		bool     $replace,
		bool     $system,
	): array
	{
		$changes = $this->changes( $transfer, $replace );

		[ $scalars, $mimes, $extensions ] = self::split( $changes );
		$this->config->apply( $scalars, $mimes, $extensions, write: false );

		if ( $system )
		{
			foreach ( $changes as $change )
			{
				$this->refuseSystem( $change );
			}
		}

		return $changes;
	}

	/**
	 * Imports: the settings and rules all at once, then, where `$system`,
	 * the system values. Nothing is written where anything is refused.
	 *
	 * @return list<TransferChange> what was changed
	 * @throws ConfigurationException
	 */
	public function apply(
		Transfer $transfer,
		bool     $replace,
		bool     $system,
	): array
	{
		$changes = $this->check( $transfer, $replace, $system );

		[ $scalars, $mimes, $extensions ] = self::split( $changes );
		$this->config->apply( $scalars, $mimes, $extensions );

		if ( ! $system )
		{
			return $changes;
		}

		foreach ( $changes as $change )
		{
			if ( $change->part === TransferChange::SYSTEM )
			{
				$this->applySystem( $change );
			}
		}

		return $changes;
	}

	/**
	 * Sets one system value, or removes it; checked before (check()).
	 *
	 * @throws ConfigurationException
	 */
	private function applySystem( TransferChange $change ): void
	{
		$after   = $change->after;
		$program = substr( $change->name, strlen( self::BINARIES ) + 1 );

		if ( $change->name === self::UNTRUSTED && is_bool( $after ) )
		{
			$this->config->setUntrustedAllowed( $after );
		}
		elseif ( $change->name === self::UNTRUSTED )
		{
			$this->config->unsetUntrustedAllowed();
		}
		elseif ( $change->name === self::SCRATCH && is_string( $after ) )
		{
			$this->scratch->set( $after );
		}
		elseif ( $change->name === self::SCRATCH )
		{
			$this->scratch->unset();
		}
		elseif ( is_string( $after ) )
		{
			$this->binaries->setOverride( $program, $after );
		}
		else
		{
			$this->binaries->unsetOverride( $program );
		}
	}

	/**
	 * A part of the file, an object or absent.
	 *
	 * @param array<array-key, mixed> $document
	 * @return array<array-key, mixed>
	 * @throws ConfigurationException
	 */
	private function part(
		array  $document,
		string $part,
	): array
	{
		$value = $document[ $part ] ?? [];

		if ( ! is_array( $value ) || ( $value !== [] && array_is_list( $value ) ) )
		{
			throw $this->refusal(
				sprintf( 'The part %s of the file is not an object.', $part ),
				$this->l10n->t( 'The part %s of the file is not an object.', [ $part ] ),
			);
		}

		return $value;
	}

	/**
	 * @param array<array-key, mixed> $settings
	 * @param list<TransferWarning>   $warnings
	 * @return array<string, int|bool|string>
	 * @throws ConfigurationException
	 */
	private function settings(
		array  $settings,
		array &$warnings,
	): array
	{
		$read = [];

		foreach ( $settings as $key => $value )
		{
			$key = (string) $key;

			if ( ! array_key_exists( $key, ConfigLexicon::SCALARS ) )
			{
				$warnings[] = new TransferWarning( TransferWarning::SETTING, $key );

				continue;
			}

			if ( ! is_int( $value ) && ! is_bool( $value ) && ! is_string( $value ) )
			{
				throw $this->refusal(
					sprintf( '%s is not a value a setting can have.', $key ),
					$this->l10n->t( '%s is not a value a setting can have.', [ $key ] ),
				);
			}

			$read[ $key ] = $this->config->normaliseScalar( $key, $value );
		}

		return $read;
	}

	/**
	 * @param array<array-key, mixed> $rules
	 * @param list<TransferWarning>   $warnings
	 * @return array<string, TypeRule>
	 * @throws ConfigurationException
	 */
	private function mimeRules(
		array  $rules,
		array &$warnings,
	): array
	{
		$read = [];

		foreach ( $rules as $mime => $fields )
		{
			$mime = strtolower( trim( (string) $mime ) );

			if ( ! SupportedTypes::matches( $mime ) )
			{
				$warnings[] = new TransferWarning( TransferWarning::TYPE, $mime );

				continue;
			}

			$read[ $mime ] = $this->rule( $mime, $fields, $warnings );
		}

		return $read;
	}

	/**
	 * @param array<array-key, mixed> $rules
	 * @param list<TransferWarning>   $warnings
	 * @return array<string, TypeRule>
	 * @throws ConfigurationException
	 */
	private function extensionRules(
		array  $rules,
		array &$warnings,
	): array
	{
		$read = [];

		foreach ( $rules as $extension => $fields )
		{
			$extension = ConfigurationService::normaliseExtension( (string) $extension );
			$mime      = $this->config->mimeOf( $extension );

			if ( $mime === null || ! SupportedTypes::matches( $mime ) )
			{
				$warnings[] = new TransferWarning( TransferWarning::EXTENSION, $extension );

				continue;
			}

			$read[ $extension ] = $this->rule( $extension, $fields, $warnings );
		}

		return $read;
	}

	/**
	 * A rule's known fields, the others left out and named.
	 *
	 * @param list<TransferWarning> $warnings
	 * @throws ConfigurationException
	 */
	private function rule(
		string $name,
		mixed  $fields,
		array  &$warnings,
	): TypeRule
	{
		if ( ! is_array( $fields ) || ( $fields !== [] && array_is_list( $fields ) ) )
		{
			throw $this->refusal(
				sprintf( 'The rule of %s is not an object.', $name ),
				$this->l10n->t( 'The rule of %s is not an object.', [ $name ] ),
			);
		}

		foreach ( array_diff( self::keys( $fields ), TypeRule::FIELDS ) as $field )
		{
			$warnings[] = new TransferWarning( TransferWarning::FIELD, $name . '.' . $field );
		}

		$rule = TypeRule::fromArray( array_intersect_key( $fields, array_flip( TypeRule::FIELDS ) ) );

		if ( is_string( $rule ) )
		{
			throw $this->refusal(
				sprintf( 'The rule of %s is not valid: %s.', $name, $rule ),
				$this->l10n->t( 'The rule of %1$s is not valid: %2$s.', [ $name, $rule ] ),
			);
		}

		return $rule;
	}

	/**
	 * The programs' paths of the system part, the unknown programs left out.
	 *
	 * @param list<TransferWarning> $warnings
	 * @return array<string, string>
	 * @throws ConfigurationException
	 */
	private function paths(
		mixed $paths,
		array &$warnings,
	): array
	{
		if ( ! is_array( $paths ) || ( $paths !== [] && array_is_list( $paths ) ) )
		{
			throw $this->refusal(
				sprintf( '%s is not an object.', self::BINARIES ),
				$this->l10n->t( '%s is not an object.', [ self::BINARIES ] ),
			);
		}

		$read = [];

		foreach ( $paths as $program => $path )
		{
			$program = (string) $program;

			if ( ! array_key_exists( $program, BinaryLocator::PROGRAMS ) )
			{
				$warnings[] = new TransferWarning( TransferWarning::PROGRAM, $program );

				continue;
			}

			if ( ! is_string( $path ) || $path === '' )
			{
				$key = self::BINARIES . '.' . $program;

				throw $this->refusal(
					sprintf( '%s is not a path.', $key ),
					$this->l10n->t( '%s is not a path.', [ $key ] ),
				);
			}

			$read[ $program ] = $path;
		}

		ksort( $read );

		return $read;
	}

	/**
	 * Refuses a system value occ would refuse to set.
	 *
	 * @throws ConfigurationException
	 */
	private function refuseSystem( TransferChange $change ): void
	{
		if ( $change->part !== TransferChange::SYSTEM || ! is_string( $change->after ) )
		{
			return;
		}

		$problem = $change->name === self::SCRATCH
			? ScratchDirectory::pathProblem( $change->after )
			: BinaryLocator::pathProblem( $change->after );

		if ( $problem !== null )
		{
			$message = sprintf( '%s: %s %s.', $change->name, $change->after, $problem );

			throw $this->refusal( $message, $message );
		}
	}

	private function refusal(
		string $message,
		string $hint,
	): ConfigurationException
	{
		return new ConfigurationException( $message, $hint );
	}


//  static methods

	/**
	 * The settings, MIME rules and extension rules of the changes, as
	 * ConfigurationService::apply() takes them: a rule's change sets its
	 * fields and clears the rest, so that it is what the file says.
	 *
	 * @param list<TransferChange> $changes
	 * @return array{0: array<string, int|bool|string|null>, 1: array<string, RuleChange|null>, 2: array<string, RuleChange|null>}
	 */
	private static function split( array $changes ): array
	{
		$scalars    = [];
		$mimes      = [];
		$extensions = [];

		foreach ( $changes as $change )
		{
			$after = $change->after;

			switch ( $change->part )
			{
				case TransferChange::SETTINGS:
					$scalars[ $change->name ] = is_array( $after ) ? null : $after;
					break;

				case TransferChange::MIMETYPES:
					$mimes[ $change->name ] = self::ruleChange( $after );
					break;

				case TransferChange::EXTENSIONS:
					$extensions[ $change->name ] = self::ruleChange( $after );
					break;
			}
		}

		return [ $scalars, $mimes, $extensions ];
	}

	/**
	 * A rule as the file has it: its fields set, the others cleared; null,
	 * nothing stored.
	 *
	 * @param int|bool|string|array<string, int|bool>|null $after
	 */
	private static function ruleChange( int|bool|string|array|null $after ): ?RuleChange
	{
		$rule = is_array( $after ) ? TypeRule::fromArray( $after ) : null;

		if ( ! $rule instanceof TypeRule )
		{
			return null;
		}

		return new RuleChange( $rule, array_values( array_diff( TypeRule::FIELDS, array_keys( $after ) ) ) );
	}

	/**
	 * A rule as stored, or null where it sets nothing.
	 *
	 * @return array<string, int|bool>|null
	 */
	private static function stored( ?TypeRule $rule ): ?array
	{
		return $rule === null || $rule->isEmpty() ? null : $rule->toArray();
	}

	/**
	 * @param array<string, TypeRule> $rules
	 * @return array<string, array<string, int|bool>>
	 */
	private static function rules( array $rules ): array
	{
		$out = array_map( static fn( TypeRule $rule ): array => $rule->toArray(), $rules );
		ksort( $out );

		return array_filter( $out );
	}

	/**
	 * A map's keys, as the strings a JSON object's are.
	 *
	 * @param array<array-key, mixed> $map
	 * @return list<string>
	 */
	private static function keys( array $map ): array
	{
		return array_map( strval( ... ), array_keys( $map ) );
	}

	/**
	 * A map as a JSON object, an empty one included.
	 *
	 * @param array<string, mixed> $map
	 * @return array<string, mixed>|stdClass
	 */
	private static function object( array $map ): array|stdClass
	{
		return $map === [] ? new stdClass() : $map;
	}
}
