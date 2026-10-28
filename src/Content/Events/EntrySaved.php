<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Psyao\Kami\Content\Entry;

/**
 * An Entry was saved through `SaveEntry`, once its transaction committed (ADR 0029).
 */
final readonly class EntrySaved implements ShouldDispatchAfterCommit
{
    /**
     * @param  Entry  $entry  The Entry as saved, its relations loaded.
     * @param  string  $locale  The Locale written, or `'*'` for an untranslatable type and a Shared-only call.
     * @param  bool  $created  Whether this save created the Entry.
     * @param  Authenticatable|null  $actor  Null for imports and the CLI.
     */
    public function __construct(
        public Entry $entry,
        public string $locale,
        public bool $created,
        public ?Authenticatable $actor,
    ) {}
}
