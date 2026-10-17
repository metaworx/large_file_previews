<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Command;

use OCA\LargeFilePreviews\Config\ConfigurationException;
use OCA\LargeFilePreviews\Service\ConfigTransfer;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reads back what config:export wrote: a restore. What the file holds is
 * checked whole, then written whole, system values included; what this app
 * does not know is left out and named, and the exit status says which of
 * the three it was.
 *
 * Nextcloud's own values the file carries are not written: their occ
 * commands are, alone on standard output, so that they can be piped to a
 * shell; everything else goes to standard error.
 *
 * @noinspection PhpUnused
 */
class ConfigImport
    extends
    Base
{

//  constants

	/** Imported, with something left out. */
	public const WARNINGS = 3;

	/** Refused: nothing written. */
	public const REFUSED = 4;


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
		$this->name( 'config:import' )
		     ->setDescription( 'Restore a configuration config:export wrote, all of it or nothing' )
		     ->addArgument( 'file', InputArgument::OPTIONAL, 'The file; - or none for standard input, where it is piped in' )
		     ->addOption( 'dry-run', null, InputOption::VALUE_NONE, 'Say what would change, and change nothing' )
		     ->addOption( 'replace', null, InputOption::VALUE_NONE, 'Return what the file does not name to its default, so that the server holds exactly what the file does' )
		     ->setHelp(
			     implode(
				     "\n",
				     [
					     'config:import /root/lfp-config.json --dry-run   what would change',
					     'config:import /root/lfp-config.json             the settings, rules and system values it names',
					     'config:import /root/lfp-config.json --replace   ... and everything else to its default',
					     'config:import < /root/lfp-config.json           the same, from standard input',
					     '',
					     'Everything is checked before anything is written, and nothing is where one',
					     'value is refused. What this app does not know - a setting, a MIME type, a',
					     'field - is left out, and named.',
					     '',
					     'Nextcloud\'s own values the file carries, its preview quality, are not',
					     'written: their occ commands are printed, alone on standard output, and',
					     'everything else on standard error. To run them as well:',
					     '',
					     '    occ lfp:config:import < /root/lfp-config.json | bash',
					     '',
					     'where occ is a command the shell knows by that name.',
					     '',
					     'Exit status: 0 imported; 3 imported, something left out; 4 refused, nothing',
					     'written; 1 an error; 2 a wrong call.',
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
		$messages = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
		$file     = $input->getArgument( 'file' );
		$piped    = ! is_string( $file ) || $file === '' || $file === '-';
		$text     = $piped ? $this->standardInput() : @file_get_contents( $file );

		if ( $text === null || $text === false )
		{
			$messages->writeln(
				$piped
					? '<error>Give a file, or pipe one in.</error>'
					: sprintf( '<error>%s cannot be read.</error>', $file ),
			);

			return self::INVALID;
		}

		$dryRun  = (bool) $input->getOption( 'dry-run' );
		$replace = (bool) $input->getOption( 'replace' );

		try
		{
			$read = $this->transfer->read( $text );

			$messages->writeln(
				sprintf(
					'%s: written by version %s on %s.',
					$piped ? 'standard input' : $file,
					$read->version ?? 'unknown',
					$read->date ?? 'an unknown date',
				),
			);

			foreach ( $read->warnings as $warning )
			{
				$messages->writeln( '<comment>' . $warning->text() . '</comment>' );
			}

			$changes = $dryRun
				? $this->transfer->check( $read, $replace, system: true )
				: $this->transfer->apply( $read, $replace, system: true );
		}
		catch ( ConfigurationException $refusal )
		{
			$messages->writeln( '<error>Refused: ' . $refusal->getMessage() . ' Nothing was written.</error>' );

			return self::REFUSED;
		}

		if ( $changes === [] )
		{
			$messages->writeln( 'Nothing to change.' );
		}
		else
		{
			$table = new Table( $messages );
			$table->setHeaders( [ 'Part', 'Name', 'Before', 'After' ] );

			foreach ( $changes as $change )
			{
				$table->addRow( [ $change->part, $change->name, self::shown( $change->before ), self::shown( $change->after ) ] );
			}

			$table->render();
			$messages->writeln(
				$dryRun
					? sprintf( 'Dry run: %d change(s), nothing written.', count( $changes ) )
					: sprintf( 'Imported: %d change(s).', count( $changes ) ),
			);
		}

		$commands = array_values( array_filter( array_map( ConfigTransfer::command( ... ), $changes ) ) );

		if ( $commands !== [] )
		{
			$messages->writeln( 'Nextcloud\'s own values are not written; their commands follow on standard output.' );

			foreach ( $commands as $command )
			{
				$output->writeln( $command, OutputInterface::OUTPUT_RAW );
			}
		}

		return $read->warnings === [] ? self::SUCCESS : self::WARNINGS;
	}


//  other non-static methods

	/**
	 * What is piped in, or null where standard input is a terminal: nothing
	 * to wait for.
	 */
	protected function standardInput(): ?string
	{
		if ( ! defined( 'STDIN' ) || stream_isatty( STDIN ) )
		{
			return null;
		}

		$text = stream_get_contents( STDIN );

		return $text === false ? null : $text;
	}


//  static methods

	/**
	 * A value as the table shows it; nothing stored as "-".
	 *
	 * @param int|bool|string|array<string, int|bool>|null $value
	 */
	private static function shown( int|bool|string|array|null $value ): string
	{
		return match ( true )
		{
			$value === null    => '-',
			is_bool( $value )  => $value ? 'true' : 'false',
			is_array( $value ) => (string) json_encode( $value, JSON_UNESCAPED_SLASHES ),
			default            => (string) $value,
		};
	}
}
