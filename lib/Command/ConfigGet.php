<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Command;

use OCA\LargeFilePreviews\Config\Resolution;
use OCA\LargeFilePreviews\Config\ValueParser;
use OCA\LargeFilePreviews\Service\ConfigurationService;
use OCA\LargeFilePreviews\Service\TypePolicy;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * What the configuration says for one case: a MIME type, an extension, a
 * size, or a real file, with each value's level and the verdict.
 *
 * `--file` answers "why did this file get no preview from the app?" in one
 * call: it also says whether the file is on local, unencrypted storage,
 * which the configuration does not decide but the provider requires.
 *
 * @noinspection PhpUnused
 */
class ConfigGet
    extends
    Base
{

//  constructor

	public function __construct(
		private readonly TypePolicy           $policy,
		private readonly ConfigurationService $config,
		private readonly IRootFolder          $rootFolder,
	)
	{
		parent::__construct();
	}


//  config/init/exe/run methods

	#[\Override]
	protected function configure(): void
	{
		$this->name( 'config:get' )
		     ->setDescription( 'Show how a MIME type, an extension or a file resolves, and whether the app takes it' )
		     ->addOption( 'mime', null, InputOption::VALUE_REQUIRED, 'A MIME type, e.g. image/tiff' )
		     ->addOption( 'ext', null, InputOption::VALUE_REQUIRED, 'A file extension, e.g. tif; alone, the MIME type is derived as Nextcloud derives it' )
		     ->addOption( 'size', null, InputOption::VALUE_REQUIRED, 'A size, in bytes or with a unit: 60mb, 2gb' )
		     ->addOption( 'file', null, InputOption::VALUE_REQUIRED, 'A file by its Nextcloud path, e.g. /alice/files/scan.pdf; gives the other three' )
		     ->addOutputOption()
		;
	}

	#[\Override]
	protected function execute(
		InputInterface  $input,
		OutputInterface $output,
	): int
	{
		$format = $this->format( $input, $output );

		if ( $format === null )
		{
			return self::INVALID;
		}

		$mime      = self::option( $input, 'mime' );
		$extension = self::option( $input, 'ext' );
		$sizeText  = self::option( $input, 'size' );
		$path      = self::option( $input, 'file' );
		$size      = null;
		$storage   = null;
		$derived   = false;

		if ( $path !== null )
		{
			try
			{
				$node = $this->rootFolder->get( $path );
			}
			catch ( NotFoundException )
			{
				$output->writeln( sprintf( '<error>No file at %s.</error>', $path ) );

				return self::FAILURE;
			}

			if ( ! $node instanceof File )
			{
				$output->writeln( sprintf( '<error>%s is not a file.</error>', $path ) );

				return self::FAILURE;
			}

			$mime      = $node->getMimetype();
			$extension = TypePolicy::extensionOf( $node->getName() );
			$size      = (int) $node->getSize();
			$storage   = [
				'local'     => $node->getStorage()->isLocal(),
				'encrypted' => $node->isEncrypted(),
			];
		}
		elseif ( $mime === null && $extension === null )
		{
			$output->writeln( '<error>Give --mime, --ext or --file.</error>' );

			return self::INVALID;
		}

		if ( $mime === null && $extension !== null )
		{
			$mime    = $this->config->mimeOf( $extension );
			$derived = true;
		}

		if ( $sizeText !== null )
		{
			$size = ValueParser::size( $sizeText );

			if ( $size === null )
			{
				$output->writeln( sprintf( '<error>"%s" is not a size: give bytes, or a number with kb, mb or gb.</error>', $sizeText ) );

				return self::INVALID;
			}
		}

		$resolution = $this->policy->resolve( $mime, $extension );
		// Without a size, only a verdict that no size changes: unreachable or
		// disabled, which the verdict checks before the size.
		$verdict = $size === null && $resolution->reachable && $resolution->enabled
			? null
			: $resolution->verdict( $size ?? 0 );

		if ( $this->writeJson(
			$output,
			$format,
			[
				...$resolution->toArray(),
				'mime_derived' => $derived,
				'size'         => $size,
				'verdict'      => $verdict?->value,
				'storage'      => $storage,
			],
		) )
		{
			return self::SUCCESS;
		}

		$this->writePlain( $output, $resolution, $derived, $size, $verdict?->describe(), $storage );

		return self::SUCCESS;
	}


//  other non-static methods

	/**
	 * @param array{local: bool, encrypted: bool}|null $storage
	 */
	private function writePlain(
		OutputInterface $output,
		Resolution      $resolution,
		bool            $derived,
		?int            $size,
		?string         $verdict,
		?array          $storage,
	): void
	{
		[ $enabled, $min, $max ] = self::fields( $resolution );

		$rows = [
			'MIME type'   => ( $resolution->mime ?? '-' ) . ( $derived ? ' (from the extension)' : '' ),
			'Extension'   => $resolution->extension ?? '-',
			'enabled'     => $enabled,
			'min_size_mb' => $min,
			'max_size_mb' => $max,
		];

		if ( $size !== null )
		{
			$rows['Size'] = sprintf( '%d bytes (%.1f MB)', $size, $size / Resolution::BYTES_PER_MB );
		}

		if ( $verdict !== null )
		{
			$rows['Verdict'] = $verdict;
		}

		if ( $storage !== null )
		{
			$rows['Storage'] = sprintf(
				'%s, %s%s',
				$storage['local'] ? 'local' : 'not local',
				$storage['encrypted'] ? 'encrypted' : 'not encrypted',
				$storage['local'] && ! $storage['encrypted']
					? ''
					: ' - the app renders only local, unencrypted files',
			);
		}

		foreach ( $rows as $label => $value )
		{
			$output->writeln( sprintf( '%-12s %s', $label, $value ) );
		}
	}


//  static methods

	private static function option(
		InputInterface $input,
		string         $name,
	): ?string
	{
		$value = $input->getOption( $name );

		return is_string( $value ) && trim( $value ) !== ''
			? trim( $value )
			: null;
	}
}
