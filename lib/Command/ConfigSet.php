<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Command;

use OCA\LargeFilePreviews\Config\ConfigurationException;
use OCA\LargeFilePreviews\Config\TypeRule;
use OCA\LargeFilePreviews\Config\ValueParser;
use OCA\LargeFilePreviews\Service\ConfigurationService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Sets a setting, or fields of a MIME type's or an extension's rule,
 * validated as the settings page validates them.
 *
 * @noinspection PhpUnused
 */
class ConfigSet
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
		$this->name( 'config:set' )
		     ->setDescription( 'Set a setting, or fields of a MIME type\'s or an extension\'s rule' )
		     ->addArgument( 'key', InputArgument::OPTIONAL, 'A setting, e.g. timeout_seconds' )
		     ->addArgument( 'value', InputArgument::OPTIONAL, 'Its value: a number, or true/false' )
		     ->addOption( 'mime', null, InputOption::VALUE_REQUIRED, 'Set the rule of this MIME type' )
		     ->addOption( 'ext', null, InputOption::VALUE_REQUIRED, 'Set the rule of this extension' )
		     ->addOption( 'enabled', null, InputOption::VALUE_REQUIRED, 'Whether the app takes the type: yes or no' )
		     ->addOption( 'min', null, InputOption::VALUE_REQUIRED, 'Smallest file taken, in MB' )
		     ->addOption( 'max', null, InputOption::VALUE_REQUIRED, 'Largest file taken, in MB; -1 for no limit' )
		     ->setHelp(
			     implode(
				     "\n",
				     [
					     'A setting:          config:set timeout_seconds 60',
					     'A MIME type\'s rule: config:set --mime image/tiff --enabled=yes --min=100',
					     'An extension\'s:     config:set --ext tif --max=2048',
					     '',
					     'Only the fields given change. An extension\'s rule overrides its MIME type\'s, field by field.',
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
		$value     = $input->getArgument( 'value' );

		try
		{
			if ( is_string( $mime ) || is_string( $extension ) )
			{
				if ( $key !== null || ( is_string( $mime ) && is_string( $extension ) ) )
				{
					$output->writeln( '<error>Give either a setting, or one of --mime and --ext.</error>' );

					return self::INVALID;
				}

				$changes = $this->changes( $input );

				if ( $changes->isEmpty() )
				{
					$output->writeln( '<error>Give at least one of --enabled, --min and --max.</error>' );

					return self::INVALID;
				}

				$rule = is_string( $mime )
					? $this->config->setMimeRule( $mime, $changes )
					: $this->config->setExtensionRule( $extension, $changes );

				$output->writeln(
					sprintf(
						'%s %s: %s',
						is_string( $mime ) ? 'MIME type' : 'Extension',
						is_string( $mime ) ? $mime : $extension,
						(string) json_encode( $rule->toArray(), JSON_UNESCAPED_SLASHES ),
					),
				);

				return self::SUCCESS;
			}

			if ( ! is_string( $key ) || ! is_string( $value ) )
			{
				$output->writeln( '<error>Give a setting and its value, or --mime or --ext with the fields to set.</error>' );

				return self::INVALID;
			}

			$this->config->setScalar( $key, $value );
			$set = $this->config->getScalar( $key );

			$output->writeln(
				sprintf(
					'%s: %s',
					$key,
					is_bool( $set ) ? ( $set ? 'true' : 'false' ) : (string) $set,
				),
			);

			return self::SUCCESS;
		}
		catch ( ConfigurationException $e )
		{
			$output->writeln( '<error>' . $e->getMessage() . '</error>' );

			return self::FAILURE;
		}
	}


//  other non-static methods

	/**
	 * @throws ConfigurationException
	 */
	private function changes( InputInterface $input ): TypeRule
	{
		return new TypeRule(
			$this->parsed( $input, 'enabled', ValueParser::bool( ... ), 'yes or no' ),
			$this->parsed( $input, 'min', ValueParser::int( ... ), 'a whole number of MB' ),
			$this->parsed( $input, 'max', ValueParser::int( ... ), 'a whole number of MB, or -1' ),
		);
	}

	/**
	 * @template T
	 * @param callable(string): (T|null) $parse
	 * @return T|null
	 * @throws ConfigurationException
	 */
	private function parsed(
		InputInterface $input,
		string         $option,
		callable       $parse,
		string         $expected,
	): mixed
	{
		$text = $input->getOption( $option );

		if ( ! is_string( $text ) )
		{
			return null;
		}

		$value = $parse( $text );

		if ( $value === null )
		{
			$message = sprintf( '--%s takes %s.', $option, $expected );

			throw new ConfigurationException( $message, $message );
		}

		return $value;
	}
}
