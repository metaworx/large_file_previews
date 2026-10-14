<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\SetupCheck;

use OCA\LargeFilePreviews\Service\ConfigurationService;
use OCA\LargeFilePreviews\Service\Prerequisite;
use OCA\LargeFilePreviews\Service\PrerequisitesService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * On the administration overview: whether previews can be rendered, and the
 * last render that failed, if it was recent.
 *
 * A log line alone goes unread; this is where a failing renderer is seen.
 * It checks only what is present - functions, extensions, programs - and
 * starts nothing.
 */
class RendererCheck
    implements
    ISetupCheck
{

//  constants

	public const RECENT_SECONDS = 86_400;


//  constructor

	public function __construct(
		private readonly PrerequisitesService $prerequisites,
		private readonly ConfigurationService $config,
		private readonly ITimeFactory         $time,
		private readonly IL10N                $l10n,
	)
	{
	}


//  getters / setters / is* / has*

	#[\Override]
	public function getCategory(): string
	{
		return 'system';
	}

	#[\Override]
	public function getName(): string
	{
		return $this->l10n->t( 'Large File Previews' );
	}


//  config/init/exe/run methods

	#[\Override]
	public function run(): SetupResult
	{
		$blocking = array_values(
			array_filter(
				$this->prerequisites->check(),
				static fn(
					Prerequisite $item,
				): bool => $item->isBlocking(),
			),
		);

		if ( $blocking !== [] )
		{
			return SetupResult::error(
				$this->l10n->t(
					'Some large previews cannot be rendered: %s. Install what is missing, or switch off what needs it; see occ large-file-previews:check.',
					[
						implode(
							', ',
							array_map(
								fn(
									Prerequisite $item,
								): string => $this->describe( $item ),
								$blocking,
							),
						),
					],
				),
			);
		}

		$failure = $this->config->lastFailure();

		if ( $failure !== null && $this->time->getTime() - $failure['time'] < self::RECENT_SECONDS )
		{
			return SetupResult::warning(
				$this->l10n->t(
					'The last large preview that failed, at %1$s, was a file of type %2$s: %3$s.',
					[
						$this->time->getDateTime( '@' . $failure['time'] )->format( 'Y-m-d H:i:s T' ),
						$failure['mime'],
						$failure['reason'],
					],
				),
			);
		}

		return SetupResult::success( $this->l10n->t( 'Large previews can be rendered.' ) );
	}


//  other non-static methods

	private function describe( Prerequisite $item ): string
	{
		return match ( $item->group )
		{
			Prerequisite::GROUP_PERMISSION => match ( true )
			{
				$item->status === Prerequisite::DISABLED => $this->l10n->t( 'the PHP function %s is disabled', [ $item->name ] ),
				$item->name === '/dev/fd'                => $this->l10n->t( '/dev/fd is missing; is /proc mounted?' ),
				default                                  => $this->l10n->t( '%s is not writable', [ $item->name ] ),
			},
			Prerequisite::GROUP_EXTENSION  => $this->l10n->t( 'the PHP extension %s is missing', [ $item->name ] ),
			default                        => $this->l10n->t( 'the program %s is missing', [ $item->name ] ),
		};
	}
}
