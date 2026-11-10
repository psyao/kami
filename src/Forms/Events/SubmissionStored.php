<?php

declare(strict_types=1);

namespace Psyao\Kami\Forms\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Psyao\Kami\Forms\Submission;

/**
 * A visitor's Submission was stored through `StoreSubmission`, once the write committed (ADR 0029): the hook for a host's own side effects.
 */
final readonly class SubmissionStored implements ShouldDispatchAfterCommit
{
    public function __construct(public Submission $submission) {}
}
