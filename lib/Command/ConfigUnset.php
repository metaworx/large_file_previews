<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Command;

use OCA\LargeFilePreviews\Config\ConfigurationException;
use OCA\LargeFilePreviews\Service\ConfigurationService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Returns a setting to its default, or clears a rule or fields of it.
 *
 * @noinspection PhpUnused
 */
class ConfigUnset
    extends
    Base
{

//  constructor

	public function __construct(
		private readonly ConfigurationService $config,
	)
	{
		parent::__construct();
	}


//  config/init/exe/run methods

	#[\Override]
	protected function configure(): void
	{
		$this->name( 'config:unset' )
		     ->setDescription( 'Return a setting to its default, or clear a MIME type\'s or an extension\'s rule' )
		     ->addArgument( 'key', InputArgument::OPTIONAL, 'A setting, e.g. timeout_seconds' )
		     ->addOption( 'mime', null, InputOption::VALUE_REQUIRED, 'Clear the rule of this MIME type' )
		     ->addOption( 'ext', null, InputOption::VALUE_REQUIRED, 'Clear the rule of this extension' )
		     ->addOption(
			     'field',
			     null,
			     InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
			     'Clear only this field: enabled, min_size_mb or max_size_mb; repeatable',
		     )
		     ->setHelp(
			     implode(
				     "\n",
				     [
					     'A setting:          config:unset timeout_seconds',
					     'A MIME type\'s rule: config:unset --mime image/tiff',
					     'One field of it:    config:unset --mime image/tiff --field min_size_mb',
					     '',
					     'A MIME type\'s whole rule returns to its shipped default; a field cleared',
					     'falls to the app\'s default. An extension\'s rule is removed.',
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
		$mime      = $input->getOption( 'mime' );
		$extension = $input->getOption( 'ext' );
		$key       = $input->getArgument( 'key' );
		/** @var list<string> $fields */
		$fields = array_values( array_filter( (array) $input->getOption( 'field' ), is_string( ... ) ) );

		try
		{
			if ( is_string( $mime ) || is_string( $extension ) )
			{
				if ( $key !== null || ( is_string( $mime ) && is_string( $extension ) ) )
				{
					$output->writeln( '<error>Give either a setting, or one of --mime and --ext.</error>' );

					return self::INVALID;
				}

				if ( is_string( $mime ) )
				{
					$this->config->unsetMimeRule( $mime, $fields );
					$output->writeln( sprintf( 'MIME type %s: cleared', $mime ) );
				}
				else
				{
					$this->config->unsetExtensionRule( $extension, $fields );
					$output->writeln( sprintf( 'Extension %s: cleared', $extension ) );
				}

				return self::SUCCESS;
			}

			if ( ! is_string( $key ) )
			{
				$output->writeln( '<error>Give a setting, or --mime or --ext.</error>' );

				return self::INVALID;
			}

			$this->config->unsetScalar( $key );
			$output->writeln( sprintf( '%s: back to its default', $key ) );

			return self::SUCCESS;
		}
		catch ( ConfigurationException $e )
		{
			$output->writeln( '<error>' . $e->getMessage() . '</error>' );

			return self::FAILURE;
		}
	}
}
