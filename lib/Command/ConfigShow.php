<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Command;

use OCA\LargeFilePreviews\Config\ConfigLexicon;
use OCA\LargeFilePreviews\Config\SupportedTypes;
use OCA\LargeFilePreviews\Service\BinaryLocator;
use OCA\LargeFilePreviews\Service\ConfigurationService;
use OCA\LargeFilePreviews\Service\TypePolicy;
use OCP\Files\IMimeTypeDetector;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The whole configuration: what is stored, where each program was found,
 * what every type resolves to, and what is set but not used.
 *
 * @noinspection PhpUnused
 */
class ConfigShow
    extends
    Base
{

//  constructor

	public function __construct(
		private readonly ConfigurationService $config,
		private readonly TypePolicy           $policy,
		private readonly BinaryLocator        $binaries,
		private readonly IMimeTypeDetector    $mimeTypes,
	)
	{
		parent::__construct();
	}


//  config/init/exe/run methods

	#[\Override]
	protected function configure(): void
	{
		$this->name( 'config:show' )
		     ->setDescription( 'Show the configuration: stored values, programs, and what every type resolves to' )
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

		$stored   = $this->stored();
		$programs = $this->programs();
		$resolved = $this->resolved();
		$problems = [ ...$this->config->problems(), ...$this->binaries->problems() ];

		if ( $this->writeJson(
			$output,
			$format,
			[
				'stored'   => $stored,
				'programs' => $programs,
				'resolved' => $resolved,
				'problems' => $problems,
			],
		) )
		{
			return self::SUCCESS;
		}

		$output->writeln( '<info>Stored</info>' );
		$table = new Table( $output );
		$table->setHeaders( [ 'Key', 'Value', '' ] );

		foreach ( $stored as $key => $entry )
		{
			// Sixteen rules do not fit a cell; the resolved table shows each.
			$value = $key === ConfigLexicon::MIMETYPES
				? 'see Resolved'
				: self::text( $entry['value'] );

			$table->addRow( [ $key, $value, $entry['stored'] ? '' : '(default)' ] );
		}

		$table->render();

		$output->writeln( '' );
		$output->writeln( '<info>Programs</info>' );
		$table = new Table( $output );
		$table->setHeaders( [ 'Program', 'Path', 'Found by' ] );

		foreach ( $programs as $program => $found )
		{
			$table->addRow(
				[
					$program,
					$found['path'] ?? ( 'missing' . ( $found['problem'] === null ? '' : ': ' . $found['problem'] ) ),
					$found['source'],
				],
			);
		}

		$table->render();
		$output->writeln( sprintf( 'A path is overridden in system config (%s) only: occ lfp:config:set binaries.<program> <path>', BinaryLocator::SYSTEM_KEY ) );

		$output->writeln( '' );
		$output->writeln( '<info>Resolved</info> (value, and the level it came from: default, mime or ext)' );
		$table = new Table( $output );
		$table->setHeaders( [ 'MIME type', 'Extension', 'Enabled', 'Min MB', 'Max MB' ] );

		foreach ( $resolved as $row )
		{
			$table->addRow(
				[
					$row['mime'] ?? '-',
					$row['extension'] ?? '',
					sprintf( '%s (%s)', $row['enabled']['value'] ? 'yes' : 'no', $row['enabled']['source'] ),
					sprintf( '%d (%s)', $row['min_size_mb']['value'], $row['min_size_mb']['source'] ),
					sprintf(
						'%s (%s)',
						$row['max_size_mb']['value'] === -1 ? 'none' : (string) $row['max_size_mb']['value'],
						$row['max_size_mb']['source'],
					),
				],
			);
		}

		$table->render();

		if ( $problems !== [] )
		{
			$output->writeln( '' );
			$output->writeln( '<comment>Set but not used</comment>' );

			foreach ( $problems as $problem )
			{
				$output->writeln( '  - ' . $problem );
			}
		}

		return self::SUCCESS;
	}


//  other non-static methods

	/**
	 * @return array<string, array{value: mixed, stored: bool}>
	 */
	private function stored(): array
	{
		$stored = [];

		foreach ( array_keys( ConfigLexicon::SCALARS ) as $key )
		{
			$stored[ $key ] = [
				'value'  => $this->config->getScalar( $key ),
				'stored' => $this->config->isStored( $key ),
			];
		}

		$stored[ ConfigLexicon::MIMETYPES ]  = [
			'value'  => array_map(
				static fn(
					$rule,
				): array => $rule->toArray(),
				$this->config->mimeRules(),
			),
			'stored' => $this->config->isStored( ConfigLexicon::MIMETYPES ),
		];
		$stored[ ConfigLexicon::EXTENSIONS ] = [
			'value'  => array_map(
				static fn(
					$rule,
				): array => $rule->toArray(),
				$this->config->extensionRules(),
			),
			'stored' => $this->config->isStored( ConfigLexicon::EXTENSIONS ),
		];

		return $stored;
	}

	/**
	 * @return array<string, array{path: ?string, source: string, problem: ?string}>
	 */
	private function programs(): array
	{
		$programs = [];

		foreach ( $this->binaries->locateAll() as $program => $location )
		{
			$programs[ $program ] = [
				'path'    => $location->path,
				'source'  => $location->source,
				'problem' => $location->problem,
			];
		}

		return $programs;
	}

	/**
	 * One row for every MIME type the provider registers for, then one for
	 * every configured extension, with the MIME type Nextcloud gives it.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function resolved(): array
	{
		$rows = [];

		foreach ( SupportedTypes::all() as $mime )
		{
			$rows[] = $this->policy->resolve( $mime, null )
			                       ->toArray()
			;
		}

		foreach ( array_keys( $this->config->extensionRules() ) as $extension )
		{
			$mime   = $this->mimeTypes->detectPath( 'file.' . $extension );
			$rows[] = $this->policy->resolve( is_string( $mime ) ? $mime : null, $extension )
			                       ->toArray()
			;
		}

		return $rows;
	}


//  static methods

	private static function text( mixed $value ): string
	{
		return match ( true )
		{
			is_bool( $value )  => $value ? 'true' : 'false',
			is_array( $value ) => $value === [] ? '{}' : (string) json_encode( $value, JSON_UNESCAPED_SLASHES ),
			default            => (string) $value,
		};
	}
}
