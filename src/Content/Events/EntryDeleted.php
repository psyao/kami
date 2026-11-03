<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * An Entry was deleted for good through `DeleteEntry`, once the write committed (ADR 0029). It carries values: the rows are gone, so a queued listener has nothing to reload.
 */
final readonly class EntryDeleted implements ShouldDispatchAfterCommit
{
    /**
     * @param  string|null  $replacementId  The Entry its addresses now redirect to; null when they answer 410.
     * @param  Authenticatable|null  $actor  Null for imports and the CLI.
     */
    public function __construct(
        public string $entryId,
        public string $type,
        public ?string $replacementId,
        public ?Authenticatable $actor,
    ) {}
}
