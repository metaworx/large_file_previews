<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Renderer;

/**
 * Which renderer handles a MIME type, if any does.
 *
 * A type can be in the provider's regex and switched on with no renderer
 * for it - an image type, until one ships - and is then declined.
 */
class Renderers
{
	/** @var list<IRenderer> */
	private readonly array $renderers;


//  constructor

	public function __construct(
		PopplerRenderer $poppler,
	)
	{
		$this->renderers = [ $poppler ];
	}


//  other non-static methods

	public function for( string $mime ): ?IRenderer
	{
		foreach ( $this->renderers as $renderer )
		{
			if ( $renderer->supports( $mime ) )
			{
				return $renderer;
			}
		}

		return null;
	}
}
