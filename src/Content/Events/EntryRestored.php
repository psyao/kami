<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Psyao\Kami\Content\Entry;

/**
 * An Entry left the Trash through `RestoreEntry`, as it was, once the write committed (ADR 0029).
 */
final readonly class EntryRestored implements ShouldDispatchAfterCommit
{
    /**
     * @param  Entry  $entry  The Entry as restored, in all its Locales.
     * @param  Authenticatable|null  $actor  Null for imports and the CLI.
     */
    public function __construct(
        public Entry $entry,
        public ?Authenticatable $actor,
    ) {}
}
