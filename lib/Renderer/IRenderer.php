<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Renderer;

use OCA\LargeFilePreviews\Service\RenderOutcome;

/**
 * Turns a local file into a PNG, JPEG or WebP image that fits a square box,
 * by running a program on its path.
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
	 * The format it writes when asked for one: that one, or what it writes
	 * instead where it cannot on this server.
	 */
	public function format( PreviewFormat $wanted ): PreviewFormat;

	/**
	 * Renders the file's first page or frame into `$output`, its longer
	 * side `$box` pixels or less, in the format its extension names.
	 *
	 * Success means the program succeeded and the file is there; whether it
	 * is an image is the caller's to check.
	 *
	 * @param string $localPath an absolute path on local storage
	 * @param string $output    an absolute path ending in the extension of
	 *                          a format format() answers
	 */
	public function render(
		string $localPath,
		int    $box,
		string $output,
	): RenderOutcome;
}
