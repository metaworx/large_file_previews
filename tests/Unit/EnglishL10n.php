<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Tests\Unit;

use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * An `IL10N` for a unit under test that translates.
 *
 * It fills a text's values the way Nextcloud's does — `%s` and `%1$s` by
 * `vsprintf`, `%n` with the count. For a class extending PHPUnit's
 * `TestCase`, whose `createMock()` it uses.
 */
trait EnglishL10n
{

//  other non-static methods

	protected function englishL10n(): IL10N&MockObject
	{
		return $this->l10nWrapping( '', '' );
	}

	/**
	 * An `IL10N` that marks what it translates: «text».
	 *
	 * For a test that must tell a translated text from one left in English:
	 * a message that reaches a person marked went through `IL10N`, one that
	 * reaches a log unmarked did not.
	 */
	protected function markingL10n(): IL10N&MockObject
	{
		return $this->l10nWrapping( '«', '»' );
	}

	private function l10nWrapping(
		string $open,
		string $close,
	): IL10N&MockObject
	{
		$l10n = $this->createMock( IL10N::class );

		$l10n->method( 't' )
		     ->willReturnCallback(
			     static fn(
				     string       $text,
				     array|string $parameters = [],
			     ): string => $open . vsprintf( $text, (array) $parameters ) . $close,
		     )
		;

		$l10n->method( 'n' )
		     ->willReturnCallback(
			     static fn(
				     string $singular,
				     string $plural,
				     int    $count,
				     array  $parameters = [],
			     ): string => $open . vsprintf(
					     str_replace( '%n', (string) $count, $count === 1 ? $singular : $plural ),
					     $parameters,
				     ) . $close,
		     )
		;

		return $l10n;
	}
}
