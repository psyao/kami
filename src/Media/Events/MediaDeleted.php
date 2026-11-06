<?php

declare(strict_types=1);

namespace Psyao\Kami\Media\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A Media item was deleted for good through `DeleteMedia`, once the write committed (ADR 0029). It carries values: the row and its files are gone, so a queued listener has nothing to reload.
 */
final readonly class MediaDeleted implements ShouldDispatchAfterCommit
{
    /** @param  Authenticatable|null  $actor  Null for imports and the CLI. */
    public function __construct(
        public string $mediaId,
        public string $site,
        public ?Authenticatable $actor,
    ) {}
}
