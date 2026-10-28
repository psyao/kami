<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Psyao\Kami\Content\Entry;

/**
 * A Translation became visible: published with its publication date reached, once the write committed (ADR 0019, ADR 0029).
 */
final readonly class EntryPublished implements ShouldDispatchAfterCommit
{
    /**
     * @param  Entry  $entry  The Entry as saved, its relations loaded.
     * @param  string  $locale  The Locale published, or `'*'` for an untranslatable type.
     * @param  bool  $scheduled  Whether a Scheduled Translation reached its moment rather than being published by a save; always `false` until scheduled announcements arrive.
     * @param  Authenticatable|null  $actor  Null for imports and the CLI.
     */
    public function __construct(
        public Entry $entry,
        public string $locale,
        public bool $scheduled,
        public ?Authenticatable $actor,
    ) {}
}
