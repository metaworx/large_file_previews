<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Command;

use OCA\LargeFilePreviews\Config\ConfigurationException;
use OCA\LargeFilePreviews\Service\BinaryLocator;
use OCA\LargeFilePreviews\Service\ConfigurationService;
use OCA\LargeFilePreviews\Service\ScratchDirectory;
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
					     'A program\'s path:   config:unset binaries.pdftoppm',
					     'Scratch directory:  config:unset scratch_directory',
					     'Untrusted decoders: config:unset vips_allow_untrusted',
					     'A MIME type\'s rule: config:unset --mime image/tiff',
					     'One field of it:    config:unset --mime image/tiff --field min_size_mb',
					     '',
					     'A MIME type\'s rule, or a field of it, returns to its shipped default;',
					     'an extension\'s field cleared falls to its MIME type\'s, and its rule goes when cleared whole or of its last field.',
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
		$target = $this->target( $input, $output, 'Give a setting, or --mime or --ext.' );

		if ( $target === null )
		{
			return self::INVALID;
		}

		[ $kind, $name ] = $target;
		$key             = (string) $input->getArgument( 'key' );
		/** @var list<string> $fields */
		$fields = array_values( array_filter( (array) $input->getOption( 'field' ), is_string( ... ) ) );

		try
		{
			switch ( $kind )
			{
				case self::TARGET_MIME:
					$this->config->unsetMimeRule( $name, $fields );
					$output->writeln( sprintf( 'MIME type %s: cleared', $name ) );

					return self::SUCCESS;

				case self::TARGET_EXTENSION:
					$this->config->unsetExtensionRule( $name, $fields );
					$output->writeln( sprintf( 'Extension %s: cleared', $name ) );

					return self::SUCCESS;

				case self::TARGET_SCRATCH:
					$this->scratch->unset();
					$output->writeln( sprintf( '%s: Nextcloud\'s temporary directory again, %s', $key, $this->scratch->path() ) );

					return self::SUCCESS;

				case self::TARGET_UNTRUSTED:
					$this->config->unsetUntrustedAllowed();
					$output->writeln( sprintf( '%s: blocked again', $key ) );

					return self::SUCCESS;

				case self::TARGET_BINARY:
					$this->binaries->unsetOverride( $name );
					$output->writeln( sprintf( '%s: found by Nextcloud again', $key ) );

					return self::SUCCESS;
			}

			$this->config->unsetScalar( $name );
			$output->writeln( sprintf( '%s: back to its default', $name ) );

			return self::SUCCESS;
		}
		catch ( ConfigurationException $e )
		{
			$output->writeln( '<error>' . $e->getMessage() . '</error>' );

			return self::FAILURE;
		}
	}
}
