<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Command;

use OCA\LargeFilePreviews\Config\Resolution;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * What the app's commands share: the `--output` option and how a resolution
 * reads in plain text.
 *
 * Each command is named `large-file-previews:<name>` with the alias
 * `lfp:<name>`.
 */
abstract class Base
    extends
    Command
{

//  constants

	protected const PREFIX = 'large-file-previews:';

	protected const ALIAS_PREFIX = 'lfp:';

	/**
	 * The key a program's path is set under: `binaries.pdftoppm`. It goes to
	 * system config, never to app config.
	 */
	protected const BINARIES = 'binaries.';

	/**
	 * The key the scratch directory is set under; system config too.
	 */
	protected const SCRATCH = 'scratch_directory';

	private const FORMATS
		 = [
			'plain',
			'json',
			'json_pretty',
		];


//  other non-static methods

	protected function name( string $name ): static
	{
		return $this->setName( self::PREFIX . $name )
		            ->setAliases( [ self::ALIAS_PREFIX . $name ] )
		;
	}

	protected function addOutputOption(): static
	{
		return $this->addOption(
			'output',
			'o',
			InputOption::VALUE_REQUIRED,
			'Output format: plain, json or json_pretty',
			'plain',
		);
	}

	/**
	 * The format asked for, or null after saying it is not one.
	 */
	protected function format(
		InputInterface  $input,
		OutputInterface $output,
	): ?string
	{
		$format = (string) $input->getOption( 'output' );

		if ( in_array( $format, self::FORMATS, true ) )
		{
			return $format;
		}

		$output->writeln( sprintf( '<error>--output takes %s.</error>', implode( ', ', self::FORMATS ) ) );

		return null;
	}

	/**
	 * Writes data as JSON; true where the format is JSON and it was written.
	 *
	 * @param array<array-key, mixed> $data
	 */
	protected function writeJson(
		OutputInterface $output,
		string          $format,
		array           $data,
	): bool
	{
		if ( $format === 'plain' )
		{
			return false;
		}

		$output->writeln(
			json_encode(
				$data,
				JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | ( $format === 'json_pretty' ? JSON_PRETTY_PRINT : 0 ),
			),
		);

		return true;
	}


//  static methods

	/**
	 * A resolution's three fields, each with the level it came from.
	 *
	 * @return array{string, string, string}
	 */
	protected static function fields( Resolution $resolution ): array
	{
		return [
			sprintf( '%s (%s)', $resolution->enabled ? 'yes' : 'no', $resolution->enabledSource->value ),
			sprintf( '%d (%s)', $resolution->minSizeMb, $resolution->minSource->value ),
			sprintf(
				'%s (%s)',
				$resolution->maxSizeMb === -1 ? 'none' : (string) $resolution->maxSizeMb,
				$resolution->maxSource->value,
			),
		];
	}
}
