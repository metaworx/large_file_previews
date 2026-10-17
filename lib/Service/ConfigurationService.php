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
use OCA\LargeFilePreviews\Config\RuleChange;
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
 * read. Several changes at once, as the page sends them, are applied
 * together or not at all (apply()).
 */
class ConfigurationService
{

//  constants

	/** Nextcloud's preview qualities the page may set, of the app config of `preview`. */
	public const PREVIEW_QUALITIES = [ 'jpeg_quality', 'webp_quality' ];

	public const PREVIEW_QUALITY_DEFAULT = 80;

	public const PREVIEW_QUALITY_MIN = 10;

	public const PREVIEW_QUALITY_MAX = 100;

	private const PREVIEW_APP = 'preview';

	private const EXTENSION_PATTERN = '/^[a-z0-9][a-z0-9_+-]{0,31}$/';

	/** @var array<string, TypeRule>|null */
	private ?array $mimeRules = null;

	/** @var array<string, TypeRule>|null what each MIME type ships with */
	private ?array $shippedMimeRules = null;

	/** @var array<string, TypeRule>|null what is stored for each, where in force */
	private ?array $storedMimeRules = null;

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
	 * Nextcloud's image providers stop (`preview_max_filesize_image`), or,
	 * where `min_size_all_types` is on, from the default minimum as every
	 * other type.
	 *
	 * @return array<string, array{enabled: bool, min_size_mb?: int}>
	 */
	public function defaultMimeTypes(): array
	{
		if ( $this->getBool( ConfigLexicon::MIN_SIZE_ALL_TYPES ) )
		{
			return ConfigLexicon::defaultMimeTypes( null );
		}

		return ConfigLexicon::defaultMimeTypes( $this->imageLimitMb() );
	}

	/**
	 * Where Nextcloud's own image providers stop, `preview_max_filesize_image`
	 * in MB; Nextcloud's default, 50, where it has no limit or none is set.
	 */
	public function imageLimitMb(): int
	{
		$limit = $this->system->getSystemValueInt( ConfigLexicon::IMAGE_LIMIT_SYSTEM_KEY, ConfigLexicon::IMAGE_DEFAULT_MIN_SIZE_MB );

		return $limit > 0 ? $limit : ConfigLexicon::IMAGE_DEFAULT_MIN_SIZE_MB;
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
	 * Whether the largest preview is handed to Nextcloud as a stream, which
	 * it stores as rendered (`preview_stream`).
	 */
	public function previewStream(): bool
	{
		return $this->getBool( ConfigLexicon::PREVIEW_STREAM );
	}

	/**
	 * Nextcloud's own preview settings, as Nextcloud reads them: the type
	 * Imaginary writes (system `preview_format`), and the quality every
	 * JPEG and WebP preview is re-encoded at (`preview` `jpeg_quality`,
	 * `webp_quality`, 80 unless set, held to 10 to 100), and the largest
	 * image its own image providers take (system `preview_max_filesize_image`,
	 * in MB, 50 unless set, -1 for none), where an image type of this app's
	 * starts; and which quality is stored rather than Nextcloud's default.
	 * Shown on the page; the qualities written by it (setPreviewQuality()),
	 * the rest never.
	 *
	 * @return array{preview_format: string, jpeg_quality: int, webp_quality: int, preview_max_filesize_image: int, quality_stored: array{jpeg_quality: bool, webp_quality: bool}}
	 */
	public function nextcloudPreview(): array
	{
		return [
			'preview_format'             => $this->system->getSystemValueString( 'preview_format', 'jpeg' ),
			'jpeg_quality'               => min( 100, max( 10, $this->appConfig->getValueInt( self::PREVIEW_APP, 'jpeg_quality', self::PREVIEW_QUALITY_DEFAULT ) ) ),
			'webp_quality'               => min( 100, max( 10, $this->appConfig->getValueInt( self::PREVIEW_APP, 'webp_quality', self::PREVIEW_QUALITY_DEFAULT ) ) ),
			'preview_max_filesize_image' => $this->system->getSystemValueInt( ConfigLexicon::IMAGE_LIMIT_SYSTEM_KEY, ConfigLexicon::IMAGE_DEFAULT_MIN_SIZE_MB ),
			'quality_stored'             => [
				'jpeg_quality' => $this->appConfig->hasKey( self::PREVIEW_APP, 'jpeg_quality' ),
				'webp_quality' => $this->appConfig->hasKey( self::PREVIEW_APP, 'webp_quality' ),
			],
		];
	}

	/**
	 * Checks one of Nextcloud's preview qualities as setPreviewQuality()
	 * would write it: one of the two, within 10 to 100, and, where it is
	 * stored, stored as a number or untyped, which an integer may replace.
	 *
	 * @throws ConfigurationException
	 */
	public function checkPreviewQuality(
		string $key,
		?int   $quality,
	): void
	{
		if ( ! in_array( $key, self::PREVIEW_QUALITIES, true ) )
		{
			throw new ConfigurationException(
				sprintf( '%s is not one of Nextcloud\'s preview qualities.', $key ),
				$this->l10n->t( '%s is not one of Nextcloud\'s preview qualities.', [ $key ] ),
			);
		}

		if ( $quality === null )
		{
			return;
		}

		if ( $quality < self::PREVIEW_QUALITY_MIN || $quality > self::PREVIEW_QUALITY_MAX )
		{
			throw new ConfigurationException(
				sprintf( '%s must be between %d and %d.', $key, self::PREVIEW_QUALITY_MIN, self::PREVIEW_QUALITY_MAX ),
				$this->l10n->t( '%1$s must be between %2$d and %3$d.', [ $key, self::PREVIEW_QUALITY_MIN, self::PREVIEW_QUALITY_MAX ] ),
			);
		}

		if ( ! $this->appConfig->hasKey( self::PREVIEW_APP, $key ) )
		{
			return;
		}

		$type = $this->appConfig->getValueType( self::PREVIEW_APP, $key ) & ~IAppConfig::VALUE_SENSITIVE;

		if ( $type !== IAppConfig::VALUE_INT && $type !== IAppConfig::VALUE_MIXED )
		{
			$command = sprintf( 'occ config:app:set preview %s --value=%d --type=integer', $key, $quality );

			throw new ConfigurationException(
				sprintf( 'preview %s is stored as another type than a number; set it with %s.', $key, $command ),
				$this->l10n->t( 'preview %1$s is stored as another type than a number; set it with %2$s.', [ $key, $command ] ),
			);
		}
	}

	/**
	 * Sets one of Nextcloud's preview qualities, `jpeg_quality` or
	 * `webp_quality` of the `preview` app, or clears it to Nextcloud's
	 * default with null. It applies to every preview Nextcloud stores from
	 * then on, not only this app's.
	 *
	 * @throws ConfigurationException
	 */
	public function setPreviewQuality(
		string $key,
		?int   $quality,
	): void
	{
		$this->checkPreviewQuality( $key, $quality );

		if ( $quality === null )
		{
			$this->appConfig->deleteKey( self::PREVIEW_APP, $key );
		}
		else
		{
			$this->appConfig->setValueInt( self::PREVIEW_APP, $key, $quality );
		}
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

	/**
	 * What system config says of the untrusted decoders, where it says
	 * anything that is a switch; null where it says nothing.
	 */
	public function untrustedStored(): ?bool
	{
		$value = $this->system->getSystemValue( ConfigLexicon::ALLOW_UNTRUSTED_SYSTEM_KEY, null );

		return is_bool( $value ) ? $value : null;
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
	 * The rule of every MIME type the provider registers for: its shipped
	 * default, with what is stored for it laid over field by field.
	 *
	 * @return array<string, TypeRule>
	 */
	public function mimeRules(): array
	{
		$this->load();

		return $this->mimeRules ?? [];
	}

	/**
	 * What each MIME type ships with, before anything is stored for it.
	 *
	 * @return array<string, TypeRule>
	 */
	public function shippedMimeRules(): array
	{
		$this->load();

		return $this->shippedMimeRules ?? [];
	}

	/**
	 * What is stored for each MIME type, its own fields only, where it is
	 * valid and in force: the fields a command must set to reproduce it.
	 *
	 * @return array<string, TypeRule>
	 */
	public function storedMimeRules(): array
	{
		$this->load();

		return $this->storedMimeRules ?? [];
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
		$this->mimeRules        = null;
		$this->shippedMimeRules = null;
		$this->storedMimeRules  = null;
		$this->extensionRules   = null;
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
		$this->apply( scalars: [ $key => $value ] );
	}

	/**
	 * Returns one scalar setting to its default.
	 *
	 * @throws ConfigurationException
	 */
	public function unsetScalar( string $key ): void
	{
		$this->apply( scalars: [ $key => null ] );
	}

	/**
	 * Lays the fields set in `$changes` over what is stored for a MIME
	 * type; what is not stored stays its shipped default's.
	 *
	 * @return TypeRule the rule now in force for the type
	 * @throws ConfigurationException
	 */
	public function setMimeRule(
		string   $mime,
		TypeRule $changes,
	): TypeRule
	{
		$this->apply( mimeRules: [ $mime => new RuleChange( $changes ) ] );

		return $this->mimeRules()[ strtolower( trim( $mime ) ) ] ?? new TypeRule();
	}

	/**
	 * Clears fields of what is stored for a MIME type, which then fall to
	 * its shipped default; with no fields, the whole rule returns to it.
	 *
	 * @param list<string> $fields
	 * @throws ConfigurationException
	 */
	public function unsetMimeRule(
		string $mime,
		array  $fields = [],
	): void
	{
		$this->apply( mimeRules: [ $mime => $fields === [] ? null : new RuleChange( clear: $fields ) ] );
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
		$this->apply( extensionRules: [ $extension => new RuleChange( $changes ) ] );

		return $this->extensionRules()[ self::normaliseExtension( $extension ) ] ?? new TypeRule();
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
		$this->apply( extensionRules: [ $extension => $fields === [] ? null : new RuleChange( clear: $fields ) ] );
	}

	/**
	 * Applies changes to settings and rules all together, or none of them:
	 * each is validated, a rule's fields together, before anything is
	 * written. The writes are app config's own, one after the other.
	 *
	 * A null setting returns to its default, a null rule is removed, a MIME
	 * type's to its shipped default. Without `$write`, everything is
	 * validated and nothing written: whether the changes would be taken.
	 *
	 * @param array<string, int|bool|string|null> $scalars
	 * @param array<string, RuleChange|null>      $mimeRules      by MIME type
	 * @param array<string, RuleChange|null>      $extensionRules by extension
	 * @throws ConfigurationException the first change refused; then nothing is written
	 */
	public function apply(
		array $scalars = [],
		array $mimeRules = [],
		array $extensionRules = [],
		bool  $write = true,
	): void
	{
		$values = [];

		foreach ( $scalars as $key => $value )
		{
			if ( $value === null )
			{
				// A key that is no setting is refused, even to unset.
				self::scalarSpec( $key, $this->l10n );
				$values[ $key ] = null;

				continue;
			}

			$values[ $key ] = $this->validScalar( $key, $value );
		}

		$mimes = $this->storedMap( ConfigLexicon::MIMETYPES );

		foreach ( $mimeRules as $mime => $change )
		{
			$mimes = $this->changedMimeRule( $mimes, (string) $mime, $change );
		}

		$extensions = $this->storedMap( ConfigLexicon::EXTENSIONS );

		foreach ( $extensionRules as $extension => $change )
		{
			$extensions = $this->changedExtensionRule( $extensions, (string) $extension, $change );
		}

		if ( ! $write )
		{
			return;
		}

		foreach ( $values as $key => $value )
		{
			match ( true )
			{
				$value === null   => $this->appConfig->deleteKey( Application::APP_ID, $key ),
				is_bool( $value ) => $this->appConfig->setValueBool( Application::APP_ID, $key, $value ),
				is_int( $value )  => $this->appConfig->setValueInt( Application::APP_ID, $key, $value ),
				default           => $this->appConfig->setValueString( Application::APP_ID, $key, $value ),
			};
		}

		// What a type ships with follows min_size_all_types.
		if ( $values !== [] )
		{
			$this->forget();
		}

		if ( $mimeRules !== [] )
		{
			$this->storeMap( ConfigLexicon::MIMETYPES, $mimes );
		}

		if ( $extensionRules !== [] )
		{
			$this->storeMap( ConfigLexicon::EXTENSIONS, $extensions );
		}
	}

	/**
	 * A setting's value as it would be stored - a switch's "yes" as true -
	 * or why it cannot be.
	 *
	 * @throws ConfigurationException
	 */
	public function normaliseScalar(
		string          $key,
		int|bool|string $value,
	): int|bool|string
	{
		return $this->validScalar( $key, $value );
	}

	/**
	 * A setting's value as it would be stored, or why it cannot be.
	 *
	 * @throws ConfigurationException
	 */
	private function validScalar(
		string          $key,
		int|bool|string $value,
	): int|bool|string
	{
		$spec = self::scalarSpec( $key, $this->l10n );

		if ( is_bool( $spec['default'] ) )
		{
			return $this->parseBool( $key, $value );
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

			return $word;
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

		return $number;
	}

	/**
	 * The stored MIME rules with one change made, and checked: only what
	 * is stored is changed, and the rule in force, the shipped default with
	 * it laid over, must be valid.
	 *
	 * @param array<array-key, mixed> $stored
	 * @return array<array-key, mixed>
	 * @throws ConfigurationException
	 */
	private function changedMimeRule(
		array       $stored,
		string      $mime,
		?RuleChange $change,
	): array
	{
		$mime = strtolower( trim( $mime ) );

		// What is not a type this app handles may still be removed, where a
		// hand-written config stored it.
		if ( ! SupportedTypes::matches( $mime ) && ( $change !== null || ! array_key_exists( $mime, $stored ) ) )
		{
			throw new ConfigurationException(
				sprintf( '%s is not a MIME type this app handles, so its files never reach it.', $mime ),
				$this->l10n->t( '%s is not a MIME type this app handles, so its files never reach it.', [ $mime ] ),
			);
		}

		if ( $change === null )
		{
			unset( $stored[ $mime ] );

			return $stored;
		}

		$this->refuseUnknownFields( $change->clear );

		$own  = TypeRule::fromArray( $stored[ $mime ] ?? [] );
		$rule = ( $own instanceof TypeRule ? $own : new TypeRule() )->without( $change->clear )->with( $change->set );
		$this->refuseInvalid( $this->shippedRule( $mime )->with( $rule ) );

		if ( $rule->isEmpty() )
		{
			unset( $stored[ $mime ] );
		}
		else
		{
			$stored[ $mime ] = $rule->toArray();
		}

		return $stored;
	}

	/**
	 * The stored extension rules with one change made, and checked.
	 *
	 * @param array<array-key, mixed> $stored
	 * @return array<array-key, mixed>
	 * @throws ConfigurationException
	 */
	private function changedExtensionRule(
		array       $stored,
		string      $extension,
		?RuleChange $change,
	): array
	{
		$extension = $this->validExtension( $extension );

		if ( $change === null )
		{
			unset( $stored[ $extension ] );

			return $stored;
		}

		$this->refuseUnknownFields( $change->clear );

		// A rule for a type the provider never sees could only mislead.
		if ( ! $change->set->isEmpty() )
		{
			$mime = $this->mimeOf( $extension );

			if ( $mime === null || ! SupportedTypes::matches( $mime ) )
			{
				$type = $mime ?? 'of no known type';

				throw new ConfigurationException(
					sprintf( 'Files .%s are %s, a type this app does not handle.', $extension, $type ),
					$this->l10n->t( 'Files .%1$s are %2$s, a type this app does not handle.', [ $extension, $type ] ),
				);
			}
		}

		$own  = TypeRule::fromArray( $stored[ $extension ] ?? [] );
		$rule = ( $own instanceof TypeRule ? $own : new TypeRule() )->without( $change->clear )->with( $change->set );
		$this->refuseInvalid( $rule );

		if ( $rule->isEmpty() )
		{
			unset( $stored[ $extension ] );
		}
		else
		{
			$stored[ $extension ] = $rule->toArray();
		}

		return $stored;
	}

	/**
	 * A MIME type's shipped default, from defaultMimeTypes().
	 */
	private function shippedRule( string $mime ): TypeRule
	{
		$rule = TypeRule::fromArray( $this->defaultMimeTypes()[ $mime ] ?? [] );

		return $rule instanceof TypeRule ? $rule : new TypeRule();
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
	 * directory, the programs' paths and Nextcloud's preview_format. A
	 * failure is remembered under it (FailedFiles), so that a change to any
	 * of them tries the file again; the rules decide which files are taken,
	 * not how one renders, and are left out.
	 */
	public function fingerprint(): string
	{
		$values = [];

		foreach ( array_keys( ConfigLexicon::SCALARS ) as $key )
		{
			$values[ $key ] = $this->getScalar( $key );
		}

		foreach ( [ ConfigLexicon::ALLOW_UNTRUSTED_SYSTEM_KEY, ScratchDirectory::SYSTEM_KEY, BinaryLocator::SYSTEM_KEY, 'preview_format' ] as $key )
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

		$shipped = $mimeRules;
		$inForce = [];

		foreach ( $this->storedMap( ConfigLexicon::MIMETYPES ) as $mime => $stored )
		{
			$mime = (string) $mime;

			if ( ! SupportedTypes::matches( $mime ) )
			{
				$this->problems[] = sprintf( '%s.%s: not a MIME type this app handles; ignored', ConfigLexicon::MIMETYPES, $mime );

				continue;
			}

			$rule = TypeRule::fromArray( $stored );

			// What is stored is laid over the shipped default, field by
			// field: a field not stored follows it, the image minimum
			// included.
			$problem = is_string( $rule )
				? $rule
				: ( $mimeRules[ $mime ] ?? new TypeRule() )->with( $rule )->problem();

			if ( $problem !== null || ! $rule instanceof TypeRule )
			{
				$this->problems[] = sprintf( '%s.%s: %s; the shipped default applies', ConfigLexicon::MIMETYPES, $mime, (string) $problem );

				continue;
			}

			$mimeRules[ $mime ] = ( $mimeRules[ $mime ] ?? new TypeRule() )->with( $rule );
			$inForce[ $mime ]   = $rule;
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

		$this->mimeRules        = $mimeRules;
		$this->shippedMimeRules = $shipped;
		$this->storedMimeRules  = $inForce;
		$this->extensionRules   = $extensionRules;
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

		$this->forget();
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
