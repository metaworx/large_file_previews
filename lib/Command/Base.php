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

	/**
	 * The key libvips' untrusted decoders are allowed under; system config
	 * too, since it widens what a user's file reaches.
	 */
	protected const UNTRUSTED = 'vips_allow_untrusted';

	/** What config:set or config:unset changes (target()). */
	protected const TARGET_MIME = 'mime';

	protected const TARGET_EXTENSION = 'ext';

	protected const TARGET_SCRATCH = 'scratch';

	protected const TARGET_UNTRUSTED = 'untrusted';

	protected const TARGET_BINARY = 'binary';

	protected const TARGET_SCALAR = 'scalar';

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
	 * What a config:set or config:unset is for, and its name: a MIME type's
	 * or an extension's rule, by `--mime` or `--ext`, the scratch directory,
	 * the untrusted decoders or a program's path, all in system config, or a
	 * setting; null after saying it is none of them.
	 *
	 * @param string $missing what to say where neither a key nor a rule is given
	 * @return array{string, string}|null self::TARGET_* and the name
	 */
	protected function target(
		InputInterface  $input,
		OutputInterface $output,
		string          $missing,
	): ?array
	{
		$mime      = $input->getOption( 'mime' );
		$extension = $input->getOption( 'ext' );
		$key       = $input->getArgument( 'key' );

		if ( is_string( $mime ) || is_string( $extension ) )
		{
			if ( $key !== null || ( is_string( $mime ) && is_string( $extension ) ) )
			{
				$output->writeln( '<error>Give either a setting, or one of --mime and --ext.</error>' );

				return null;
			}

			return is_string( $mime )
				? [ self::TARGET_MIME, $mime ]
				: [ self::TARGET_EXTENSION, $extension ];
		}

		if ( ! is_string( $key ) )
		{
			$output->writeln( '<error>' . $missing . '</error>' );

			return null;
		}

		return match ( true )
		{
			$key === self::SCRATCH                  => [ self::TARGET_SCRATCH, $key ],
			$key === self::UNTRUSTED                => [ self::TARGET_UNTRUSTED, $key ],
			str_starts_with( $key, self::BINARIES ) => [ self::TARGET_BINARY, substr( $key, strlen( self::BINARIES ) ) ],
			default                                 => [ self::TARGET_SCALAR, $key ],
		};
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
