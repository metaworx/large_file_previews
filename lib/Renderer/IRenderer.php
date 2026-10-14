<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Renderer;

use OCA\LargeFilePreviews\Service\RenderOutcome;

/**
 * Turns a local file into a PNG that fits a square box, by running a
 * program on its path.
 */
interface IRenderer
{

//  other non-static methods

	/**
	 * Whether this renderer handles files of the MIME type.
	 */
	public function supports( string $mime ): bool;

	/**
	 * The program it runs, by the name BinaryLocator finds it under.
	 */
	public function program(): string;

	/**
	 * Why it cannot decode files of a type it supports on this server, or
	 * null where nothing says it cannot.
	 */
	public function unsupported( string $mime ): ?Unsupported;

	/**
	 * Renders the file's first page or frame into `$outputPng`, its longer
	 * side `$box` pixels or less.
	 *
	 * Success means the program succeeded and the file is there; whether it
	 * is an image is the caller's to check.
	 *
	 * @param string $localPath an absolute path on local storage
	 * @param string $outputPng an absolute path ending in `.png`
	 */
	public function render(
		string $localPath,
		int    $box,
		string $outputPng,
	): RenderOutcome;
}
