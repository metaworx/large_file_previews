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
use OCA\LargeFilePreviews\Service\BinaryLocator;
use OCA\LargeFilePreviews\Service\ConfigurationService;
use OCA\LargeFilePreviews\Service\ScratchDirectory;
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
		private readonly BinaryLocator        $binaries,
		private readonly ScratchDirectory     $scratch,
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
					     'A program\'s path:   config:set binaries.pdftoppm /opt/poppler/bin/pdftoppm   (system config)',
					     'Scratch directory:  config:set scratch_directory /var/cache/large_file_previews   (system config)',
					     'Untrusted decoders: config:set vips_allow_untrusted true   (system config)',
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
		$missing = 'Give a setting and its value, or --mime or --ext with the fields to set.';
		$target  = $this->target( $input, $output, $missing );

		if ( $target === null )
		{
			return self::INVALID;
		}

		[ $kind, $name ] = $target;
		$key             = (string) $input->getArgument( 'key' );
		$value           = $input->getArgument( 'value' );

		try
		{
			if ( $kind === self::TARGET_MIME || $kind === self::TARGET_EXTENSION )
			{
				$changes = $this->changes( $input );

				if ( $changes->isEmpty() )
				{
					$output->writeln( '<error>Give at least one of --enabled, --min and --max.</error>' );

					return self::INVALID;
				}

				$rule = $kind === self::TARGET_MIME
					? $this->config->setMimeRule( $name, $changes )
					: $this->config->setExtensionRule( $name, $changes );

				$output->writeln(
					sprintf(
						'%s %s: %s',
						$kind === self::TARGET_MIME ? 'MIME type' : 'Extension',
						$name,
						(string) json_encode( $rule->toArray(), JSON_UNESCAPED_SLASHES ),
					),
				);

				return self::SUCCESS;
			}

			if ( ! is_string( $value ) )
			{
				$output->writeln( '<error>' . $missing . '</error>' );

				return self::INVALID;
			}

			// A path, and the untrusted decoders, go to system config, never
			// to app config.
			switch ( $kind )
			{
				case self::TARGET_SCRATCH:
					$this->scratch->set( $value );
					$output->writeln( sprintf( '%s: %s (system config)', $key, $this->scratch->path() ) );

					return self::SUCCESS;

				case self::TARGET_UNTRUSTED:
					$allowed = ValueParser::bool( $value );

					if ( $allowed === null )
					{
						$output->writeln( sprintf( '<error>%s takes true or false.</error>', $key ) );

						return self::INVALID;
					}

					$this->config->setUntrustedAllowed( $allowed );
					$output->writeln( sprintf( '%s: %s (system config)', $key, $allowed ? 'true' : 'false' ) );

					return self::SUCCESS;

				case self::TARGET_BINARY:
					$this->binaries->setOverride( $name, $value );
					$output->writeln( sprintf( '%s: %s (system config)', $key, $value ) );

					return self::SUCCESS;
			}

			$this->config->setScalar( $name, $value );
			$set = $this->config->getScalar( $name );

			$output->writeln(
				sprintf(
					'%s: %s',
					$name,
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
