<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Command;

use OCA\LargeFilePreviews\Service\BinaryLocator;
use OCA\LargeFilePreviews\Service\Platform;
use OCA\LargeFilePreviews\Service\Prerequisite;
use OCA\LargeFilePreviews\Service\PrerequisitesService;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Everything the app needs from the server: the PHP functions that start a
 * program, the extensions, and each program with its path and version.
 *
 * Exits 0 when nothing the configuration needs is missing, 1 otherwise, so
 * a script can ask.
 *
 * @noinspection PhpUnused
 */
class Check
    extends
    Base
{

//  constructor

	public function __construct(
		private readonly PrerequisitesService $prerequisites,
		private readonly BinaryLocator        $binaries,
	)
	{
		parent::__construct();
	}


//  config/init/exe/run methods

	#[\Override]
	protected function configure(): void
	{
		$this->name( 'check' )
		     ->setDescription( 'List what the app needs from the server - PHP functions, extensions, programs - and whether it is there' )
		     ->addOption( 'refresh', null, InputOption::VALUE_NONE, 'Find the programs and their versions again, rather than from the cache' )
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

		if ( $input->getOption( 'refresh' ) === true )
		{
			$this->binaries->refresh();
		}

		$items    = $this->prerequisites->check( true );
		$blocking = $this->prerequisites->blocking( $items );

		if ( $this->writeJson(
			$output,
			$format,
			[
				'ready' => ! $blocking,
				'items' => array_map(
					static fn(
						Prerequisite $item,
					): array => $item->toArray(),
					$items,
				),
			],
		) )
		{
			return $blocking ? self::FAILURE : self::SUCCESS;
		}

		$table = new Table( $output );
		$table->setHeaders( [ 'Kind', 'Name', 'Status', 'Needed for', 'Detail', 'Install' ] );

		foreach ( $items as $item )
		{
			$table->addRow(
				[
					$item->group,
					$item->name,
					$item->isBlocking() ? '<error>' . $item->status . '</error>' : $item->status,
					$item->neededFor,
					$item->detail,
					Platform::text( $item->install ),
				],
			);
		}

		$table->render();

		$platform = $this->prerequisites->platformName();
		$output->writeln(
			$platform === null
				? 'Platform: not one the app knows packages for; see the README for each program\'s package.'
				: sprintf( 'Platform: %s.', $platform ),
		);
		$output->writeln(
			$blocking
				? '<error>Not ready: large previews are not rendered until what is missing is there, or its limit is switched off.</error>'
				: '<info>Ready.</info>',
		);

		return $blocking ? self::FAILURE : self::SUCCESS;
	}
}
