<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Psyao\Kami\Content\Entry;

/**
 * An Entry was copied through `DuplicateEntry`, once the write committed (ADR 0029). No `EntrySaved` comes with it.
 */
final readonly class EntryDuplicated implements ShouldDispatchAfterCommit
{
    /**
     * @param  Entry  $source  The Entry copied, unchanged.
     * @param  Entry  $copy  The new Entry, every Translation a Draft.
     * @param  Authenticatable|null  $actor  Null for imports and the CLI.
     */
    public function __construct(
        public Entry $source,
        public Entry $copy,
        public ?Authenticatable $actor,
    ) {}
}
