<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Command;

use OCA\LargeFilePreviews\Service\ConfigTransfer;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Writes what is stored as a file config:import reads back: a backup.
 *
 * @noinspection PhpUnused
 */
class ConfigExport
    extends
    Base
{

//  constructor

	public function __construct(
		private readonly ConfigTransfer $transfer,
	)
	{
		parent::__construct();
	}


//  config/init/exe/run methods

	#[\Override]
	protected function configure(): void
	{
		$this->name( 'config:export' )
		     ->setDescription( 'Write the configuration stored as JSON, for config:import to read back' )
		     ->addArgument( 'file', InputArgument::OPTIONAL, 'Where to write it; standard output where none is given' )
		     ->setHelp(
			     implode(
				     "\n",
				     [
					     'config:export                       the JSON on standard output',
					     'config:export /root/lfp-config.json  written to the file',
					     '',
					     'Only what is stored is written: a setting at its default, or a MIME type as it',
					     'ships, is not, so that a restore follows the defaults of the version it runs on.',
					     'The system values - programs\' paths, the untrusted decoders, the scratch',
					     'directory - are written where they are set.',
				     ],
			     ),
		     )
		;
	}

	#[\Override]
	protected function execute(
		InputInterface  $input,
		OutputInterface $output,
	): int
	{
		$json = json_encode( $this->transfer->export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
		$file = $input->getArgument( 'file' );

		if ( ! is_string( $file ) || $file === '' || $file === '-' )
		{
			$output->write( $json, false, OutputInterface::OUTPUT_RAW );

			return self::SUCCESS;
		}

		if ( @file_put_contents( $file, $json ) === false )
		{
			$output->writeln( sprintf( '<error>%s could not be written.</error>', $file ) );

			return self::FAILURE;
		}

		$output->writeln( sprintf( 'Written to %s.', $file ) );

		return self::SUCCESS;
	}
}
