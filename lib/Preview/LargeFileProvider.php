<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Preview;

use OCA\LargeFilePreviews\Config\SupportedTypes;
use OCA\LargeFilePreviews\Renderer\Renderers;
use OCA\LargeFilePreviews\Service\ConfigurationService;
use OCA\LargeFilePreviews\Service\Outcome;
use OCA\LargeFilePreviews\Service\RenderOutcome;
use OCA\LargeFilePreviews\Service\TypePolicy;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\IImage;
use OCP\ITempManager;
use OCP\Preview\IProviderV2;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The preview provider: one regex, longer than any other provider's for the
 * same types, so Nextcloud asks this one first, and one renderer per type.
 *
 * It never throws. Nextcloud ends the provider chain for a file at the first
 * exception and answers with a server error, so every failure here returns
 * null instead, and the next provider gets its turn.
 *
 * `isAvailable()` runs for every preview of every type in the regex, every
 * image included: it does no I/O, only the type, the name, the size the file
 * cache holds and the configuration. `getThumbnail()` is asked for the
 * largest preview only; Nextcloud makes every smaller size from it.
 */
class LargeFileProvider
    implements
    IProviderV2
{

//  constructor

	public function __construct(
		private readonly TypePolicy           $policy,
		private readonly Renderers            $renderers,
		private readonly ImageLoader          $images,
		private readonly ITempManager         $tempManager,
		private readonly ConfigurationService $config,
		private readonly ITimeFactory         $time,
		private readonly LoggerInterface      $logger,
	)
	{
	}


//  getters / setters / is* / has*

	#[\Override]
	public function getMimeType(): string
	{
		return SupportedTypes::MIME_REGEX;
	}

	#[\Override]
	public function isAvailable( FileInfo $file ): bool
	{
		try
		{
			$resolution = $this->policy->resolveFile( $file );
			$verdict    = $resolution->verdict( (int) $file->getSize() );

			// Off is the ordinary answer for every image on the instance;
			// it is not worth a line in the log.
			if ( ! $resolution->reachable || ! $resolution->enabled )
			{
				return false;
			}

			$renderer = $this->renderers->for( (string) $resolution->mime );

			// A type whose decoder is missing or blocked goes to the next
			// provider, which may well read it.
			$reason = match ( true )
			{
				! $verdict->isTaken()            => $verdict->describe(),
				$renderer === null               => 'no renderer for this type',
				$file->isEncrypted()             => 'encrypted',
				! $file->getStorage()->isLocal() => 'not on local storage',
				default                          => $renderer->unsupported( (string) $resolution->mime )?->describe(),
			};

			if ( $reason !== null )
			{
				$this->logger->debug(
					'Declined {name} ({mime}): {reason}',
					[
						'name'   => $file->getName(),
						'mime'   => $resolution->mime,
						'reason' => $reason,
					],
				);

				return false;
			}

			return true;
		}
		catch ( Throwable $e )
		{
			$this->logger->warning(
				'Could not decide whether to render {name}: {message}',
				[
					'name'      => $file->getName(),
					'message'   => $e->getMessage(),
					'exception' => $e,
				],
			);

			return false;
		}
	}

	#[\Override]
	public function getThumbnail(
		File $file,
		int  $maxX,
		int  $maxY,
	): ?IImage
	{
		$folder = null;

		try
		{
			$mime     = $file->getMimetype();
			$renderer = $this->renderers->for( $mime );

			if ( $renderer === null )
			{
				return null;
			}

			// The real path on local storage: no copy is made. Anything that
			// has none was declined by isAvailable(), but the storage is asked
			// again rather than trusted.
			$localPath = $file->getStorage()->getLocalFile( $file->getInternalPath() );

			if ( ! is_string( $localPath ) || $localPath === '' )
			{
				return $this->failed( $file, new RenderOutcome( Outcome::START_FAILED, 'no local path' ) );
			}

			$folder = $this->tempManager->getTemporaryFolder();

			if ( $folder === false )
			{
				return $this->failed( $file, new RenderOutcome( Outcome::START_FAILED, 'no temporary folder' ) );
			}

			// Nextcloud stores the preview in the type it is given.
			$format  = $renderer->format( $this->config->previewFormat() );
			$output  = rtrim( $folder, '/' ) . '/preview.' . $format->extension();
			$outcome = $renderer->render( $localPath, max( $maxX, $maxY ), $output );

			if ( ! $outcome->isSuccess() )
			{
				return $this->failed( $file, $outcome );
			}

			$image = $this->images->load( $output );

			if ( $image === null )
			{
				return $this->failed( $file, $outcome->as( Outcome::INVALID_OUTPUT, 'the output is not an image' ) );
			}

			// The box was square; the preview need not be.
			$image->scaleDownToFit( $maxX, $maxY );

			return $image;
		}
		catch ( Throwable $e )
		{
			$this->logger->warning(
				'Could not render {name}: {message}',
				[
					'name'      => $file->getName(),
					'message'   => $e->getMessage(),
					'exception' => $e,
				],
			);

			try
			{
				$this->config->recordFailure(
					$this->time->getTime(),
					$file->getMimetype(),
					new RenderOutcome( Outcome::START_FAILED, 'unexpected error: ' . $e->getMessage() ),
				);
			}
			catch ( Throwable )
			{
				// Already logged; the record is a convenience.
			}

			return null;
		}
		finally
		{
			if ( is_string( $folder ) )
			{
				self::remove( $folder );
			}
		}
	}


//  other non-static methods

	private function failed(
		File          $file,
		RenderOutcome $outcome,
	): null
	{
		$this->logger->warning(
			'Could not render {name} ({mime}): {reason}{stderr}',
			[
				'name'        => $file->getName(),
				'mime'        => $file->getMimetype(),
				'reason'      => $outcome->reason,
				'stderr'      => $outcome->stderr === '' ? '' : '; ' . strtok( $outcome->stderr, "\n" ),
				'outcome'     => $outcome->outcome->value,
				'exit_status' => $outcome->exitStatus,
			],
		);

		// For the setup check: a log line alone goes unread.
		try
		{
			$this->config->recordFailure( $this->time->getTime(), $file->getMimetype(), $outcome );
		}
		catch ( Throwable $e )
		{
			$this->logger->warning(
				'Could not record the failure: {message}',
				[
					'message'   => $e->getMessage(),
					'exception' => $e,
				],
			);
		}

		return null;
	}


//  static methods

	/**
	 * Removes the render's folder: the PNG, and whatever else the program
	 * left there.
	 */
	private static function remove( string $folder ): void
	{
		foreach ( glob( rtrim( $folder, '/' ) . '/*' ) ?: [] as $entry )
		{
			@unlink( $entry );
		}

		@rmdir( $folder );
	}
}
