<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Service;

use OCA\LargeFilePreviews\AppInfo\Application;
use OCA\LargeFilePreviews\Config\ConfigLexicon;
use OCA\LargeFilePreviews\Config\ConfigurationException;
use OCA\LargeFilePreviews\Config\Limits;
use OCA\LargeFilePreviews\Config\SupportedTypes;
use OCA\LargeFilePreviews\Config\TypeRule;
use OCA\LargeFilePreviews\Config\ValueParser;
use OCA\LargeFilePreviews\Renderer\PreviewFormat;
use OCP\Files\IMimeTypeDetector;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;

/**
 * The one place the app's settings are read and written.
 *
 * The occ commands and the settings page both write through here, so a
 * value is checked the same way whichever way it comes. A write that is not
 * valid is refused with its reason, never quietly corrected: whoever asked
 * for it should hear that it was not done. What is read is checked too,
 * since `occ config:app:set` writes past this class: a number outside its
 * range is held to it, and a rule that is not valid is left out and
 * reported by {@see problems()}.
 *
 * The type rules are read once per request; a write here drops what was
 * read.
 */
class ConfigurationService
{

//  constants

	private const EXTENSION_PATTERN = '/^[a-z0-9][a-z0-9_+-]{0,31}$/';

	/** @var array<string, TypeRule>|null */
	private ?array $mimeRules = null;

	/** @var array<string, TypeRule>|null */
	private ?array $extensionRules = null;

	/** @var list<string> */
	private array $problems = [];


//  constructor

	public function __construct(
		private readonly IAppConfig        $appConfig,
		private readonly IL10N             $l10n,
		private readonly IMimeTypeDetector $mimeTypes,
		private readonly IConfig           $system,
	)
	{
	}


//  other non-static methods

	/**
	 * What each MIME type has until it is set, its images from where
	 * Nextcloud's image providers stop (`preview_max_filesize_image`).
	 *
	 * @return array<string, array{enabled: bool, min_size_mb?: int}>
	 */
	public function defaultMimeTypes(): array
	{
		$limit = $this->system->getSystemValueInt( ConfigLexicon::IMAGE_LIMIT_SYSTEM_KEY, ConfigLexicon::IMAGE_DEFAULT_MIN_SIZE_MB );

		return ConfigLexicon::defaultMimeTypes( $limit > 0 ? $limit : ConfigLexicon::IMAGE_DEFAULT_MIN_SIZE_MB );
	}

	public function minSizeMb(): int
	{
		return $this->getInt( ConfigLexicon::MIN_SIZE_MB );
	}

	public function maxSizeMb(): int
	{
		return $this->getInt( ConfigLexicon::MAX_SIZE_MB );
	}

	public function limits(): Limits
	{
		return new Limits(
			$this->getBool( ConfigLexicon::TIMEOUT_ENABLED ),
			$this->getInt( ConfigLexicon::TIMEOUT_SECONDS ),
			$this->getInt( ConfigLexicon::TIMEOUT_KILL_AFTER_SECONDS ),
			$this->getBool( ConfigLexicon::PRLIMIT_ENABLED ),
			$this->getInt( ConfigLexicon::PRLIMIT_AS_MB ),
			$this->getBool( ConfigLexicon::CHOOM_ENABLED ),
			$this->getInt( ConfigLexicon::CHOOM_ADJUST ),
			$this->getInt( ConfigLexicon::PRLIMIT_FSIZE_MB ) * 1_048_576,
		);
	}


//  getters / setters / is* / has*

	/**
	 * One scalar setting, held to its range or its words.
	 */
	public function getScalar( string $key ): int|bool|string
	{
		$spec = self::scalarSpec( $key );

		return match ( true )
		{
			is_bool( $spec['default'] )   => $this->getBool( $key ),
			is_string( $spec['default'] ) => $this->getWord( $key ),
			default                       => $this->getInt( $key ),
		};
	}

	/**
	 * The type previews are written in; `nextcloud` reads the system
	 * setting Imaginary follows, `preview_format`: WebP where it says so,
	 * JPEG otherwise, as Imaginary does.
	 */
	public function previewFormat(): PreviewFormat
	{
		$format = $this->getWord( ConfigLexicon::PREVIEW_FORMAT );

		if ( $format === 'nextcloud' )
		{
			$format = $this->system->getSystemValueString( 'preview_format', 'jpeg' ) === 'webp'
				? 'webp'
				: 'jpeg';
		}

		return PreviewFormat::from( $format );
	}

	/**
	 * Whether libvips may use its untrusted decoders, from system config:
	 *
	 *     'large_file_previews.vips_allow_untrusted' => true,
	 *
	 * set with `occ lfp:config:set vips_allow_untrusted true`. Blocked
	 * unless the value is true itself: which decoders a user's file reaches
	 * is for whoever can write config.php, who can run code already, not
	 * for an administrator without shell access.
	 */
	public function untrustedAllowed(): bool
	{
		return $this->system->getSystemValue( ConfigLexicon::ALLOW_UNTRUSTED_SYSTEM_KEY, false ) === true;
	}

	/**
	 * From occ only.
	 */
	public function setUntrustedAllowed( bool $allowed ): void
	{
		$this->system->setSystemValue( ConfigLexicon::ALLOW_UNTRUSTED_SYSTEM_KEY, $allowed );
	}

	public function unsetUntrustedAllowed(): void
	{
		$this->system->deleteSystemValue( ConfigLexicon::ALLOW_UNTRUSTED_SYSTEM_KEY );
	}

	/**
	 * Whether a key has a value of its own, rather than its default.
	 */
	public function isStored( string $key ): bool
	{
		return $this->appConfig->hasKey( Application::APP_ID, $key );
	}

	/**
	 * The rule of every MIME type the provider registers for: what is
	 * stored for it, or its shipped default.
	 *
	 * @return array<string, TypeRule>
	 */
	public function mimeRules(): array
	{
		$this->load();

		return $this->mimeRules ?? [];
	}

	/**
	 * The rule of every configured extension, keyed by the extension.
	 *
	 * @return array<string, TypeRule>
	 */
	public function extensionRules(): array
	{
		$this->load();

		return $this->extensionRules ?? [];
	}

	/**
	 * Whether any image type is switched on: by its MIME type, or by an
	 * extension that enables files of one.
	 */
	public function imagesEnabled(): bool
	{
		foreach ( $this->mimeRules() as $mime => $rule )
		{
			if ( $rule->enabled === true && in_array( $mime, SupportedTypes::IMAGE_TYPES, true ) )
			{
				return true;
			}
		}

		foreach ( $this->extensionRules() as $extension => $rule )
		{
			if ( $rule->enabled === true && in_array( $this->mimeOf( (string) $extension ), SupportedTypes::IMAGE_TYPES, true ) )
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * What is stored but left out because it is not valid, in English.
	 *
	 * @return list<string>
	 */
	public function problems(): array
	{
		$this->load();

		return $this->problems;
	}

	/**
	 * The MIME type Nextcloud gives files of an extension, or null where it
	 * knows none.
	 */
	public function mimeOf( string $extension ): ?string
	{
		$mime = $this->mimeTypes->detectPath( 'file.' . self::normaliseExtension( $extension ) );

		return is_string( $mime ) && $mime !== '' && $mime !== 'application/octet-stream'
			? $mime
			: null;
	}

	/**
	 * Drops the rules read so far, so the next question reads them again.
	 *
	 * A request reads them once. A process that outlives many changes - a
	 * long preview run, a test suite - calls this to see a change made past
	 * this class.
	 */
	public function forget(): void
	{
		$this->mimeRules      = null;
		$this->extensionRules = null;
	}

	/**
	 * Sets one scalar setting.
	 *
	 * A string is read as occ passes it: `true`/`false`, `yes`/`no`, `on`/`off`
	 * or `1`/`0` for a switch, digits with an optional minus for a number,
	 * one of its words, in any case, for a word.
	 *
	 * @throws ConfigurationException
	 */
	public function setScalar(
		string          $key,
		int|bool|string $value,
	): void
	{
		$spec = self::scalarSpec( $key, $this->l10n );

		if ( is_bool( $spec['default'] ) )
		{
			$this->appConfig->setValueBool( Application::APP_ID, $key, $this->parseBool( $key, $value ) );

			return;
		}

		if ( isset( $spec['choices'] ) )
		{
			$word = is_string( $value ) ? strtolower( trim( $value ) ) : null;

			if ( $word === null || ! in_array( $word, $spec['choices'], true ) )
			{
				$choices = implode( ', ', $spec['choices'] );

				throw new ConfigurationException(
					sprintf( '%s must be one of %s.', $key, $choices ),
					$this->l10n->t( '%1$s must be one of %2$s.', [ $key, $choices ] ),
				);
			}

			$this->appConfig->setValueString( Application::APP_ID, $key, $word );

			return;
		}

		$number = $this->parseInt( $key, $value );
		$min    = $spec['min'] ?? PHP_INT_MIN;
		$max    = $spec['max'] ?? PHP_INT_MAX;

		if ( $number < $min || $number > $max )
		{
			throw $max === PHP_INT_MAX
				? new ConfigurationException(
					sprintf( '%s must be at least %d.', $key, $min ),
					$this->l10n->t( '%1$s must be at least %2$d.', [ $key, $min ] ),
				)
				: new ConfigurationException(
					sprintf( '%s must be between %d and %d.', $key, $min, $max ),
					$this->l10n->t( '%1$s must be between %2$d and %3$d.', [ $key, $min, $max ] ),
				);
		}

		$this->appConfig->setValueInt( Application::APP_ID, $key, $number );
	}

	/**
	 * Returns one scalar setting to its default.
	 *
	 * @throws ConfigurationException
	 */
	public function unsetScalar( string $key ): void
	{
		self::scalarSpec( $key, $this->l10n );

		$this->appConfig->deleteKey( Application::APP_ID, $key );
	}

	/**
	 * Lays the fields set in `$changes` over a MIME type's rule.
	 *
	 * @return TypeRule the rule now in force for the type
	 * @throws ConfigurationException
	 */
	public function setMimeRule(
		string   $mime,
		TypeRule $changes,
	): TypeRule
	{
		$mime = strtolower( trim( $mime ) );

		if ( ! SupportedTypes::matches( $mime ) )
		{
			throw new ConfigurationException(
				sprintf( '%s is not a MIME type this app handles, so its files never reach it.', $mime ),
				$this->l10n->t( '%s is not a MIME type this app handles, so its files never reach it.', [ $mime ] ),
			);
		}

		$rule = ( $this->mimeRules()[ $mime ] ?? new TypeRule() )->with( $changes );
		$this->refuseInvalid( $rule );

		$stored          = $this->storedMap( ConfigLexicon::MIMETYPES );
		$stored[ $mime ] = $rule->toArray();
		$this->storeMap( ConfigLexicon::MIMETYPES, $stored );

		return $rule;
	}

	/**
	 * Clears fields of a MIME type's rule, which then fall to the app's
	 * default; with no fields, the whole rule returns to its shipped
	 * default.
	 *
	 * @param list<string> $fields
	 * @throws ConfigurationException
	 */
	public function unsetMimeRule(
		string $mime,
		array  $fields = [],
	): void
	{
		$mime = strtolower( trim( $mime ) );
		$this->refuseUnknownFields( $fields );

		$stored = $this->storedMap( ConfigLexicon::MIMETYPES );

		if ( $fields === [] )
		{
			unset( $stored[ $mime ] );
		}
		else
		{
			$rule            = ( $this->mimeRules()[ $mime ] ?? new TypeRule() )->without( $fields );
			$stored[ $mime ] = $rule->toArray();
		}

		$this->storeMap( ConfigLexicon::MIMETYPES, $stored );
	}

	/**
	 * Lays the fields set in `$changes` over an extension's rule, creating
	 * it where there is none.
	 *
	 * @return TypeRule the extension's rule as now stored
	 * @throws ConfigurationException
	 */
	public function setExtensionRule(
		string   $extension,
		TypeRule $changes,
	): TypeRule
	{
		$extension = $this->validExtension( $extension );
		$mime      = $this->mimeOf( $extension );

		// A rule for a type the provider never sees could only mislead.
		if ( $mime === null || ! SupportedTypes::matches( $mime ) )
		{
			$type = $mime ?? 'of no known type';

			throw new ConfigurationException(
				sprintf( 'Files .%s are %s, a type this app does not handle.', $extension, $type ),
				$this->l10n->t( 'Files .%1$s are %2$s, a type this app does not handle.', [ $extension, $type ] ),
			);
		}

		$rule = ( $this->extensionRules()[ $extension ] ?? new TypeRule() )->with( $changes );
		$this->refuseInvalid( $rule );

		$stored               = $this->storedMap( ConfigLexicon::EXTENSIONS );
		$stored[ $extension ] = $rule->toArray();
		$this->storeMap( ConfigLexicon::EXTENSIONS, $stored );

		return $rule;
	}

	/**
	 * Clears fields of an extension's rule; with no fields, or once none is
	 * left, the extension's rule is gone.
	 *
	 * @param list<string> $fields
	 * @throws ConfigurationException
	 */
	public function unsetExtensionRule(
		string $extension,
		array  $fields = [],
	): void
	{
		$extension = $this->validExtension( $extension );
		$this->refuseUnknownFields( $fields );

		$stored = $this->storedMap( ConfigLexicon::EXTENSIONS );
		$rule   = ( $this->extensionRules()[ $extension ] ?? new TypeRule() )->without(
			$fields === []
				? TypeRule::FIELDS
				: $fields,
		);

		if ( $rule->isEmpty() )
		{
			unset( $stored[ $extension ] );
		}
		else
		{
			$stored[ $extension ] = $rule->toArray();
		}

		$this->storeMap( ConfigLexicon::EXTENSIONS, $stored );
	}

	/**
	 * The last failed render, or null where none is recorded.
	 *
	 * @return array{time: int, mime: string, outcome: string, reason: string, exit_status: ?int}|null
	 */
	public function lastFailure(): ?array
	{
		$stored = $this->appConfig->getValueArray( Application::APP_ID, ConfigLexicon::LAST_FAILURE, [], true );

		if ( ! is_int( $stored['time'] ?? null ) || ! is_string( $stored['reason'] ?? null ) )
		{
			return null;
		}

		return [
			'time'        => $stored['time'],
			'mime'        => is_string( $stored['mime'] ?? null ) ? $stored['mime'] : '',
			'outcome'     => is_string( $stored['outcome'] ?? null ) ? $stored['outcome'] : '',
			'reason'      => $stored['reason'],
			'exit_status' => is_int( $stored['exit_status'] ?? null ) ? $stored['exit_status'] : null,
		];
	}

	/**
	 * Records a failed render, replacing the one before.
	 *
	 * Written on failure only, so a render that succeeds costs no write.
	 * Concurrent failures may overwrite each other; the last one wins,
	 * which is all the record promises.
	 */
	public function recordFailure(
		int           $time,
		string        $mime,
		RenderOutcome $outcome,
	): void
	{
		$this->appConfig->setValueArray(
			Application::APP_ID,
			ConfigLexicon::LAST_FAILURE,
			[
				'time'        => $time,
				'mime'        => $mime,
				'outcome'     => $outcome->outcome->value,
				'reason'      => $outcome->reason,
				'exit_status' => $outcome->exitStatus,
			],
			true,
		);
	}

	/**
	 * What a render's outcome depends on besides the file: every setting,
	 * and what system config says of untrusted decoders, the scratch
	 * directory and the programs' paths. A failure is remembered under it
	 * (FailedFiles), so that a change to any of them tries the file again.
	 */
	public function fingerprint(): string
	{
		$values = [];

		foreach ( array_keys( ConfigLexicon::SCALARS ) as $key )
		{
			$values[ $key ] = $this->getScalar( $key );
		}

		foreach ( [ ConfigLexicon::ALLOW_UNTRUSTED_SYSTEM_KEY, ScratchDirectory::SYSTEM_KEY, BinaryLocator::SYSTEM_KEY ] as $key )
		{
			$values[ $key ] = $this->system->getSystemValue( $key, null );
		}

		return md5( (string) json_encode( $values ) );
	}

	private function getInt( string $key ): int
	{
		$spec  = self::scalarSpec( $key );
		$value = $this->appConfig->getValueInt( Application::APP_ID, $key, (int) $spec['default'] );

		return max( $spec['min'] ?? PHP_INT_MIN, min( $spec['max'] ?? PHP_INT_MAX, $value ) );
	}

	private function getBool( string $key ): bool
	{
		return $this->appConfig->getValueBool( Application::APP_ID, $key, (bool) self::scalarSpec( $key )['default'] );
	}

	/**
	 * A word, or its default where what is stored is not one of its words.
	 */
	private function getWord( string $key ): string
	{
		$spec  = self::scalarSpec( $key );
		$value = $this->appConfig->getValueString( Application::APP_ID, $key, (string) $spec['default'] );

		return in_array( $value, $spec['choices'] ?? [], true )
			? $value
			: (string) $spec['default'];
	}

	/**
	 * Reads both rule maps, keeping what is valid and noting what is not.
	 */
	private function load(): void
	{
		if ( $this->mimeRules !== null && $this->extensionRules !== null )
		{
			return;
		}

		$this->problems = [];
		$mimeRules      = [];

		foreach ( $this->defaultMimeTypes() as $mime => $default )
		{
			$rule = TypeRule::fromArray( $default );

			if ( $rule instanceof TypeRule )
			{
				$mimeRules[ $mime ] = $rule;
			}
		}

		foreach ( $this->storedMap( ConfigLexicon::MIMETYPES ) as $mime => $stored )
		{
			$mime = (string) $mime;

			if ( ! SupportedTypes::matches( $mime ) )
			{
				$this->problems[] = sprintf( '%s.%s: not a MIME type this app handles; ignored', ConfigLexicon::MIMETYPES, $mime );

				continue;
			}

			$rule = TypeRule::fromArray( $stored );

			if ( is_string( $rule ) )
			{
				$this->problems[] = sprintf( '%s.%s: %s; the shipped default applies', ConfigLexicon::MIMETYPES, $mime, $rule );

				continue;
			}

			$mimeRules[ $mime ] = $rule;
		}

		$extensionRules = [];

		foreach ( $this->storedMap( ConfigLexicon::EXTENSIONS ) as $extension => $stored )
		{
			$extension = (string) $extension;

			if ( preg_match( self::EXTENSION_PATTERN, $extension ) !== 1 )
			{
				$this->problems[] = sprintf( '%s.%s: not a lower-case extension; ignored', ConfigLexicon::EXTENSIONS, $extension );

				continue;
			}

			$rule = TypeRule::fromArray( $stored );

			if ( is_string( $rule ) )
			{
				$this->problems[] = sprintf( '%s.%s: %s; ignored', ConfigLexicon::EXTENSIONS, $extension, $rule );

				continue;
			}

			// Kept, so that it can be seen and removed; it changes nothing.
			$mime = $this->mimeOf( $extension );

			if ( $mime === null || ! SupportedTypes::matches( $mime ) )
			{
				$this->problems[] = sprintf(
					'%s.%s: %s is not a type this app handles; it has no effect',
					ConfigLexicon::EXTENSIONS,
					$extension,
					$mime ?? 'no known type',
				);
			}

			$extensionRules[ $extension ] = $rule;
		}

		$this->mimeRules      = $mimeRules;
		$this->extensionRules = $extensionRules;
	}

	/**
	 * What is stored under a map key, without the defaults; an empty array
	 * where nothing is.
	 *
	 * @return array<array-key, mixed>
	 */
	private function storedMap( string $key ): array
	{
		if ( ! $this->appConfig->hasKey( Application::APP_ID, $key ) )
		{
			return [];
		}

		return $this->appConfig->getValueArray( Application::APP_ID, $key );
	}

	/**
	 * @param array<array-key, mixed> $map
	 */
	private function storeMap(
		string $key,
		array  $map,
	): void
	{
		ksort( $map );

		if ( $map === [] )
		{
			$this->appConfig->deleteKey( Application::APP_ID, $key );
		}
		else
		{
			$this->appConfig->setValueArray( Application::APP_ID, $key, $map );
		}

		$this->mimeRules      = null;
		$this->extensionRules = null;
	}

	/**
	 * @throws ConfigurationException
	 */
	private function refuseInvalid( TypeRule $rule ): void
	{
		$problem = $rule->problem();

		if ( $problem === null )
		{
			return;
		}

		// The rule says what is wrong in English; the reader's language
		// follows from the same three conditions, in the same order.
		throw new ConfigurationException(
			$problem . '.',
			match ( true )
			{
				$rule->minSizeMb !== null && $rule->minSizeMb < 0  => $this->l10n->t( 'The minimum size cannot be below 0.' ),
				$rule->maxSizeMb !== null && $rule->maxSizeMb < -1 => $this->l10n->t( 'The maximum size cannot be below -1, which means no limit.' ),
				default                                            => $this->l10n->t( 'The minimum size cannot be above the maximum size.' ),
			},
		);
	}

	/**
	 * @param list<string> $fields
	 * @throws ConfigurationException
	 */
	private function refuseUnknownFields( array $fields ): void
	{
		$unknown = array_diff( $fields, TypeRule::FIELDS );

		if ( $unknown === [] )
		{
			return;
		}

		$list = implode( ', ', $unknown );

		throw new ConfigurationException(
			sprintf( 'Unknown field %s; the fields are %s.', $list, implode( ', ', TypeRule::FIELDS ) ),
			$this->l10n->t( 'Unknown field %1$s; the fields are %2$s.', [ $list, implode( ', ', TypeRule::FIELDS ) ] ),
		);
	}

	/**
	 * @throws ConfigurationException
	 */
	private function validExtension( string $extension ): string
	{
		$normal = self::normaliseExtension( $extension );

		if ( preg_match( self::EXTENSION_PATTERN, $normal ) !== 1 )
		{
			throw new ConfigurationException(
				sprintf( '"%s" is not a file extension.', $extension ),
				$this->l10n->t( '"%s" is not a file extension.', [ $extension ] ),
			);
		}

		return $normal;
	}

	/**
	 * @throws ConfigurationException
	 */
	private function parseBool(
		string          $key,
		int|bool|string $value,
	): bool
	{
		$parsed = is_bool( $value )
			? $value
			: ValueParser::bool( (string) $value );

		if ( $parsed === null )
		{
			throw new ConfigurationException(
				sprintf( '%s takes true or false.', $key ),
				$this->l10n->t( '%s takes true or false.', [ $key ] ),
			);
		}

		return $parsed;
	}

	/**
	 * @throws ConfigurationException
	 */
	private function parseInt(
		string          $key,
		int|bool|string $value,
	): int
	{
		$parsed = match ( true )
		{
			is_int( $value )    => $value,
			is_string( $value ) => ValueParser::int( $value ),
			default             => null,
		};

		if ( $parsed !== null )
		{
			return $parsed;
		}

		throw new ConfigurationException(
			sprintf( '%s takes a whole number.', $key ),
			$this->l10n->t( '%s takes a whole number.', [ $key ] ),
		);
	}


//  static methods

	/**
	 * An extension as rules are keyed: lower case, without a leading dot.
	 */
	public static function normaliseExtension( string $extension ): string
	{
		return strtolower( ltrim( trim( $extension ), '.' ) );
	}

	/**
	 * @return array{default: int|bool|string, min?: int, max?: int|null, choices?: list<string>, definition: string}
	 * @throws ConfigurationException
	 */
	private static function scalarSpec(
		string $key,
		?IL10N $l10n = null,
	): array
	{
		$spec = ConfigLexicon::SCALARS[ $key ] ?? null;

		if ( $spec !== null )
		{
			return $spec;
		}

		$known = implode( ', ', array_keys( ConfigLexicon::SCALARS ) );

		throw new ConfigurationException(
			sprintf( '%s is not a setting of this app; the settings are %s.', $key, $known ),
			$l10n?->t( '%1$s is not a setting of this app; the settings are %2$s.', [ $key, $known ] )
			?? sprintf( '%s is not a setting of this app.', $key ),
		);
	}
}
