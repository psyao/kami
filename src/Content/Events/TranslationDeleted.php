<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A never-published Translation was deleted through `DeleteTranslation`, once the write committed (ADR 0029). It carries values: the row is gone, so a queued listener has nothing to reload.
 */
final readonly class TranslationDeleted implements ShouldDispatchAfterCommit
{
    /**
     * @param  Authenticatable|null  $actor  Null for imports and the CLI.
     */
    public function __construct(
        public string $entryId,
        public string $type,
        public string $locale,
        public ?Authenticatable $actor,
    ) {}
}
