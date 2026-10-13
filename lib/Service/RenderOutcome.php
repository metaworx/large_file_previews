<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Service;

/**
 * How a run ended, with the reason in English for the log and the setup
 * check, and what the program said on stderr, cut short.
 */
final readonly class RenderOutcome
{

//  constructor

	/**
	 * @param int|null $exitStatus as the outermost program reported it, or null where it never ran
	 * @param int|null $signal     the signal that ended it, where one did
	 */
	public function __construct(
		public Outcome $outcome,
		public string  $reason,
		public ?int    $exitStatus = null,
		public ?int    $signal = null,
		public string  $stderr = '',
	)
	{
	}


//  getters / setters / is* / has*

	public function isSuccess(): bool
	{
		return $this->outcome->isSuccess();
	}


//  other non-static methods

	/**
	 * The same run, judged again by what it left behind.
	 */
	public function as(
		Outcome $outcome,
		string  $reason,
	): self
	{
		return new self( $outcome, $reason, $this->exitStatus, $this->signal, $this->stderr );
	}

	/**
	 * @return array{outcome: string, reason: string, exit_status: ?int, signal: ?int, stderr: string}
	 */
	public function toArray(): array
	{
		return [
			'outcome'     => $this->outcome->value,
			'reason'      => $this->reason,
			'exit_status' => $this->exitStatus,
			'signal'      => $this->signal,
			'stderr'      => $this->stderr,
		];
	}
}
