<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Unit\Service;

use Closure;
use OCA\LargeFilePreviews\Preview\LargeFileProvider;
use OCA\LargeFilePreviews\Service\ProviderChain;
use OCP\IPreview;
use OCP\Preview\IProviderV2;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class ProviderChainTest
    extends
    TestCase
{
//  properties

	/**
	 * Providers under class names of their own, made once: a mock class's
	 * name cannot be declared twice in one process.
	 *
	 * @var array<string, IProviderV2>
	 */
	private static array $named = [];


//  other non-static methods

	/**
	 * Ours first for a type only it registers; another provider's regex,
	 * shorter, after it.
	 */
	public function testThePreviewProvidersInTheOrderNextcloudTriesThem(): void
	{
		$chain = $this->chain(
			[
				'/^(application\\/pdf|image\\/png)$/' => [ $this->ours() ],
				'/image\\/png/'                       => [ $this->named( 'LfpTestPng' ) ],
			],
		);

		self::assertSame(
			[
				[ 'name' => ProviderChain::OURS, 'class' => LargeFileProvider::class, 'ours' => true, 'before' => false ],
				[ 'name' => 'LfpTestPng', 'class' => 'LfpTestPng', 'ours' => false, 'before' => false ],
			],
			self::strip( $chain->forMime( 'image/png' ) ),
		);
		self::assertSame( [ ProviderChain::OURS ], array_column( $chain->forMime( 'application/pdf' ), 'name' ) );
	}

	/**
	 * A provider listed ahead of ours is asked first, and marked.
	 */
	public function testAProviderBeforeOursIsMarked(): void
	{
		$chain = $this->chain(
			[
				'/^application\\/pdf$|^application\\/x-anything-longer$/' => [ $this->named( 'LfpTestImaginaryPdf' ) ],
				'/^application\\/pdf$/'                                   => [ $this->ours(), $this->named( 'LfpTestPdf' ) ],
			],
		);

		self::assertSame(
			[ [ 'LfpTestImaginaryPdf', true ], [ ProviderChain::OURS, false ], [ 'LfpTestPdf', false ] ],
			array_map(
				static fn(
					array $provider,
				): array => [ $provider['name'], $provider['before'] ],
				$chain->forMime( 'application/pdf' ),
			),
		);
	}

	/**
	 * Without this app's provider, nothing is before it.
	 */
	public function testNothingIsBeforeAProviderThatIsNotThere(): void
	{
		$chain = $this->chain( [ '/image\\/png/' => [ $this->named( 'LfpTestPng' ) ] ] );

		self::assertSame( [ false ], array_column( $chain->forMime( 'image/png' ), 'before' ) );
		self::assertSame( [], $chain->forMime( 'text/plain' ) );
	}

	/**
	 * A provider the server cannot build is skipped, as the generator skips
	 * it; one that throws is logged and skipped. Each is built once.
	 */
	public function testAProviderThatIsNotThereIsLeftOut(): void
	{
		$calls  = 0;
		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( self::once() )
		       ->method( 'info' )
		;

		$chain = $this->chain(
			[
				'/image\\/png/' => [
					static fn(): false => false,
					static function() use ( &$calls ): never
					{
						$calls++;

						throw new RuntimeException( 'no' );
					},
					$this->ours(),
				],
			],
			$logger,
		);

		self::assertSame( [ ProviderChain::OURS ], array_column( $chain->forMime( 'image/png' ), 'name' ) );
		self::assertSame( [ ProviderChain::OURS ], array_column( $chain->forMime( 'image/png' ), 'name' ) );
		self::assertSame( 1, $calls );
	}

	/**
	 * @param array<string, list<Closure>> $providers
	 */
	private function chain(
		array            $providers,
		?LoggerInterface $logger = null,
	): ProviderChain
	{
		$previews = $this->createMock( IPreview::class );
		$previews->method( 'getProviders' )
		         ->willReturn( $providers )
		;

		return new ProviderChain( $previews, $logger ?? $this->createMock( LoggerInterface::class ) );
	}

	private function named( string $class ): Closure
	{
		self::$named[ $class ] ??= $this->getMockBuilder( IProviderV2::class )
		                                ->setMockClassName( $class )
		                                ->getMock()
		;
		$provider                = self::$named[ $class ];

		return static fn(): IProviderV2 => $provider;
	}

	private function ours(): Closure
	{
		$provider = $this->createMock( LargeFileProvider::class );

		return static fn(): IProviderV2 => $provider;
	}


//  static methods

	/**
	 * The chain with this app's provider's class as declared rather than
	 * its mock's.
	 *
	 * @param list<array{name: string, class: string, ours: bool, before: bool}> $chain
	 *
	 * @return list<array{name: string, class: string, ours: bool, before: bool}>
	 */
	private static function strip( array $chain ): array
	{
		foreach ( $chain as $index => $provider )
		{
			if ( $provider['ours'] )
			{
				$chain[ $index ]['class'] = LargeFileProvider::class;
			}
		}

		return $chain;
	}
}
