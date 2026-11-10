<?php

declare(strict_types=1);

namespace Psyao\Kami\Forms\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Submissions of one Form were deleted for good through `DeleteSubmissions`, once the write committed (ADR 0029). It carries values: the rows are gone, so a queued listener has nothing to reload.
 */
final readonly class SubmissionsDeleted implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<string>  $submissionIds  Those the call was given, of this Form.
     * @param  Authenticatable|null  $actor  Null for the CLI.
     */
    public function __construct(
        public string $form,
        public array $submissionIds,
        public ?Authenticatable $actor,
    ) {}
}
