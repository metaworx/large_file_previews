<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Service;

/**
 * How one program runs, beside the configured limits.
 *
 * A file is handed to a program as a descriptor PHP opened, and named to it
 * as `/dev/fd/<n>`: the user's file name never reaches a program or what it
 * prints, and the file cannot be replaced between two runs. Measured with
 * poppler 24.02, libvips 8.15 and libtiff 4.5, each reads and writes such a
 * path; opening `/dev/fd/<n>` reopens the same file, even one already
 * unlinked.
 */
final readonly class RunOptions
{

//  constants

	/** The descriptor the user's file is handed on. */
	public const INPUT = 4;

	/** The descriptor a working copy is handed on. */
	public const COPY = 3;


//  constructor

	/**
	 * @param bool                  $keepStdout  for a program that answers on
	 *                                           stdout: kept with stderr, in the
	 *                                           outcome's `stderr`
	 * @param array<string, string> $environment variables the program needs,
	 *                                           besides the C locale it always
	 *                                           runs in
	 * @param int|null              $prlimitAsMb a renderer's own address-space
	 *                                           cap, in place of the configured
	 *                                           one; `prlimit_enabled` still
	 *                                           decides whether there is a cap
	 * @param array<int, resource>  $descriptors open files the program gets
	 *                                           besides stdin, stdout and
	 *                                           stderr, by descriptor number
	 * @param int|null              $fsizeBytes  the largest file the program
	 *                                           may write, in place of
	 *                                           prlimit_fsize_mb: the one-strip
	 *                                           copy's own
	 * @param Deadline|null         $deadline    the render's: the run's timeout
	 *                                           is the time left, and it is not
	 *                                           started once none is
	 */
	public function __construct(
		public bool      $keepStdout = false,
		public array     $environment = [],
		public ?int      $prlimitAsMb = null,
		public array     $descriptors = [],
		public ?int      $fsizeBytes = null,
		public ?Deadline $deadline = null,
	)
	{
	}


//  static methods

	/**
	 * The path a program opens a handed descriptor by.
	 */
	public static function path( int $descriptor ): string
	{
		return '/dev/fd/' . $descriptor;
	}
}
