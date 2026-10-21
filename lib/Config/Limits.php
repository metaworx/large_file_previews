<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Config;

/**
 * The limits each render runs under, each switchable, with its value.
 */
final readonly class Limits
{

//  constructor

	public function __construct(
		public bool $timeoutEnabled,
		public int  $timeoutSeconds,
		public int  $timeoutKillAfterSeconds,
		public bool $prlimitEnabled,
		public int  $prlimitAsMb,
		public bool $choomEnabled,
		public int  $choomAdjust,
		// The largest file a run may write, in bytes: its output, stderr and
		// any spill; switched with the address-space cap (prlimit_enabled).
		public int  $prlimitFsizeBytes = 1_073_741_824,
		// Each run in a bubblewrap sandbox (Sandbox): auto, on or off.
		public string $sandbox = ConfigLexicon::SANDBOX_OFF,
	)
	{
	}


//  other non-static methods

	/**
	 * The same limits with a run's own address-space cap, its own
	 * file-size cap, a renderer's or the one-strip copy's, and the time its
	 * render has left.
	 */
	public function withRun(
		?int $prlimitAsMb,
		?int $prlimitFsizeBytes = null,
		?int $timeoutSeconds = null,
	): self
	{
		return new self(
			$this->timeoutEnabled,
			$timeoutSeconds === null ? $this->timeoutSeconds : min( $this->timeoutSeconds, $timeoutSeconds ),
			$this->timeoutKillAfterSeconds,
			$this->prlimitEnabled,
			$prlimitAsMb ?? $this->prlimitAsMb,
			$this->choomEnabled,
			$this->choomAdjust,
			$prlimitFsizeBytes ?? $this->prlimitFsizeBytes,
			$this->sandbox,
		);
	}

	/**
	 * The timeout alone: for asking a program its version or its loaders,
	 * which need no cap and read no user's file, but must not hang a
	 * request.
	 */
	public function timeoutOnly(): self
	{
		return new self(
			$this->timeoutEnabled,
			$this->timeoutSeconds,
			$this->timeoutKillAfterSeconds,
			false,
			$this->prlimitAsMb,
			false,
			$this->choomAdjust,
			$this->prlimitFsizeBytes,
		);
	}
}
