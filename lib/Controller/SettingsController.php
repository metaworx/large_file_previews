<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Controller;

use OCA\LargeFilePreviews\AppInfo\Application;
use OCA\LargeFilePreviews\Config\ConfigLexicon;
use OCA\LargeFilePreviews\Config\ConfigurationException;
use OCA\LargeFilePreviews\Config\SupportedTypes;
use OCA\LargeFilePreviews\Config\Resolution;
use OCA\LargeFilePreviews\Config\TypeRule;
use OCA\LargeFilePreviews\Renderer\Renderers;
use OCA\LargeFilePreviews\Service\BinaryLocator;
use OCA\LargeFilePreviews\Service\ConfigurationService;
use OCA\LargeFilePreviews\Service\Prerequisite;
use OCA\LargeFilePreviews\Service\PrerequisitesService;
use OCA\LargeFilePreviews\Service\TypePolicy;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * What the settings page reads and writes: the whole configuration, and
 * partial changes to it.
 *
 * Admin only, by the absence of #[NoAdminRequired], which a test pins; CSRF
 * is checked on every route. Every write goes through ConfigurationService,
 * as occ's do, so the page refuses what the commands refuse, with the same
 * reason in the reader's language.
 *
 * @noinspection PhpUnused
 */
class SettingsController
    extends
    Controller
{

//  constructor

	public function __construct(
		IRequest                              $request,
		private readonly ConfigurationService $config,
		private readonly TypePolicy           $policy,
		private readonly Renderers            $renderers,
		private readonly PrerequisitesService $prerequisites,
		private readonly BinaryLocator        $binaries,
		private readonly IL10N                $l10n,
	)
	{
		parent::__construct( Application::APP_ID, $request );
	}


//  other non-static methods

	/**
	 * The configuration, what it resolves to, and what the server has.
	 */
	#[ApiRoute( verb: 'GET', url: '/settings' )]
	public function get(): DataResponse
	{
		return new DataResponse( $this->state() );
	}

	/**
	 * Applies the changes in the body, each part optional:
	 *
	 *     {"scalars":    {"timeout_seconds": 60, "choom_enabled": null},
	 *      "mimetypes":  {"image/tiff": {"enabled": true, "min_size_mb": null}},
	 *      "extensions": {"tif": null}}
	 *
	 * A null clears: a setting back to its default, a field to the level
	 * below, a whole rule to its shipped default or away. The first refusal
	 * stops the rest and answers 400 with its reason; the page sends one
	 * change at a time.
	 */
	#[ApiRoute( verb: 'PUT', url: '/settings' )]
	public function update(): DataResponse
	{
		$body = json_decode( $this->readRequestBody(), true );

		if ( ! is_array( $body ) )
		{
			return $this->refused( $this->l10n->t( 'The request is not valid.' ) );
		}

		try
		{
			foreach ( self::map( $body, 'scalars' ) as $key => $value )
			{
				$value === null
					? $this->config->unsetScalar( (string) $key )
					: $this->config->setScalar( (string) $key, self::scalar( $value ) );
			}

			foreach ( self::map( $body, 'mimetypes' ) as $mime => $fields )
			{
				$this->applyRule( (string) $mime, $fields, true );
			}

			foreach ( self::map( $body, 'extensions' ) as $extension => $fields )
			{
				$this->applyRule( (string) $extension, $fields, false );
			}
		}
		catch ( ConfigurationException $e )
		{
			return $this->refused( $e->hint );
		}

		return new DataResponse( $this->state() );
	}

	/**
	 * @throws ConfigurationException
	 */
	private function applyRule(
		string $name,
		mixed  $fields,
		bool   $isMime,
	): void
	{
		if ( $fields === null )
		{
			$isMime
				? $this->config->unsetMimeRule( $name )
				: $this->config->unsetExtensionRule( $name );

			return;
		}

		if ( ! is_array( $fields ) )
		{
			throw new ConfigurationException( 'A rule is an object.', $this->l10n->t( 'The request is not valid.' ) );
		}

		$cleared = array_keys(
			array_filter(
				$fields,
				static fn(
					mixed $value,
				): bool => $value === null,
			),
		);

		if ( $cleared !== [] )
		{
			/** @var list<string> $cleared */
			$isMime
				? $this->config->unsetMimeRule( $name, $cleared )
				: $this->config->unsetExtensionRule( $name, $cleared );
		}

		$set = array_diff_key( $fields, array_flip( $cleared ) );

		if ( $set === [] )
		{
			return;
		}

		$rule = TypeRule::fromArray( $set );

		if ( is_string( $rule ) )
		{
			throw new ConfigurationException( $rule, $this->l10n->t( 'The request is not valid.' ) );
		}

		$isMime
			? $this->config->setMimeRule( $name, $rule )
			: $this->config->setExtensionRule( $name, $rule );
	}

	/**
	 * The raw body; a test supplies its own.
	 */
	protected function readRequestBody(): string
	{
		return (string) file_get_contents( 'php://input' );
	}

	/**
	 * Which program renders a MIME type, and whether it is there.
	 *
	 * @return array{program: ?string, available: bool} no program where the provider never sees the type
	 */
	private function renderer( ?string $mime ): array
	{
		$renderer = $mime === null || ! SupportedTypes::matches( $mime )
			? null
			: $this->renderers->for( $mime );

		if ( $renderer === null )
		{
			return [
				'program'   => null,
				'available' => false,
			];
		}

		return [
			'program'   => $renderer->program(),
			'available' => $this->binaries->locate( $renderer->program() )->isFound(),
		];
	}

	private function refused( string $reason ): DataResponse
	{
		return new DataResponse( [ 'error' => $reason ], Http::STATUS_BAD_REQUEST );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function state(): array
	{
		$scalars = [];

		foreach ( ConfigLexicon::SCALARS as $key => $spec )
		{
			$scalars[ $key ] = [
				'value'   => $this->config->getScalar( $key ),
				'default' => $spec['default'],
				'min'     => $spec['min'] ?? null,
				'max'     => $spec['max'] ?? null,
				'stored'  => $this->config->isStored( $key ),
			];
		}

		$defaults  = ConfigLexicon::defaultMimeTypes();
		$mimeTypes = [];
		$active    = [];

		foreach ( SupportedTypes::all() as $mime )
		{
			$resolution  = $this->policy->resolve( $mime, null );
			$renderer    = $this->renderer( $mime );
			$mimeTypes[] = [
				'mime'     => $mime,
				'rule'     => ( $this->config->mimeRules()[ $mime ] ?? new TypeRule() )->toArray(),
				'default'  => $defaults[ $mime ],
				'renderer' => $renderer,
			];

			if ( $resolution->enabled )
			{
				$active[] = self::activeRow( $resolution, 'enabled', $renderer );
			}
		}

		$extensions = [];

		foreach ( $this->config->extensionRules() as $extension => $rule )
		{
			$mime       = $this->config->mimeOf( $extension );
			$resolution = $this->policy->resolve( $mime, $extension );
			$renderer   = $this->renderer( $mime );
			$byMime     = $mime !== null && $this->policy->resolve( $mime, null )->enabled;

			$extensions[] = [
				'extension' => $extension,
				'mime'      => $mime,
				'rule'      => $rule->toArray(),
				'renderer'  => $renderer,
				// Selected anyway by its MIME type, with no size of its own.
				'noEffect'  => $rule->enabled === true && $rule->minSizeMb === null && $rule->maxSizeMb === null && $byMime,
			];

			$active[] = self::activeRow(
				$resolution,
				match ( true )
				{
					$rule->enabled === false => 'excluded',
					$resolution->enabled     => 'enabled',
					default                  => 'disabled',
				},
				$renderer,
			);
		}

		$prerequisites = $this->prerequisites->check();

		return [
			'scalars'       => $scalars,
			'active'        => $active,
			'mimetypes'     => $mimeTypes,
			'extensions'    => $extensions,
			'prerequisites' => array_map(
				static fn(
					Prerequisite $item,
				): array => $item->toArray(),
				$prerequisites,
			),
			'ready'         => ! $this->prerequisites->blocking( $prerequisites ),
			'problems'      => $this->config->problems(),
			'lastFailure'   => $this->config->lastFailure(),
			'overrideKey'   => BinaryLocator::SYSTEM_KEY,
		];
	}


//  static methods

	/**
	 * One row of what the configuration resolves to: processing, renderer,
	 * and each size with the level it came from.
	 *
	 * @param array{program: ?string, available: bool} $renderer
	 * @return array<string, mixed>
	 */
	private static function activeRow(
		Resolution $resolution,
		string     $processing,
		array      $renderer,
	): array
	{
		return [
			'mime'        => $resolution->mime,
			'extension'   => $resolution->extension,
			'processing'  => $processing,
			'renderer'    => $renderer,
			'min_size_mb' => [
				'value'  => $resolution->minSizeMb,
				'source' => $resolution->minSource->value,
			],
			'max_size_mb' => [
				'value'  => $resolution->maxSizeMb,
				'source' => $resolution->maxSource->value,
			],
		];
	}

	/**
	 * @param array<array-key, mixed> $body
	 * @return array<array-key, mixed>
	 */
	private static function map(
		array  $body,
		string $key,
	): array
	{
		return is_array( $body[ $key ] ?? null ) ? $body[ $key ] : [];
	}

	private static function scalar( mixed $value ): int|bool|string
	{
		return is_int( $value ) || is_bool( $value )
			? $value
			: ( is_scalar( $value ) ? (string) $value : '' );
	}
}
