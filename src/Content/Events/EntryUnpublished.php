<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Psyao\Kami\Content\Entry;

/**
 * A published Translation returned to Draft, once the write committed (ADR 0019, ADR 0029).
 */
final readonly class EntryUnpublished implements ShouldDispatchAfterCommit
{
    /**
     * @param  Entry  $entry  The Entry as saved, its relations loaded.
     * @param  string  $locale  The Locale unpublished, or `'*'` for an untranslatable type.
     * @param  Authenticatable|null  $actor  Null for imports and the CLI.
     */
    public function __construct(
        public Entry $entry,
        public string $locale,
        public ?Authenticatable $actor,
    ) {}
}
