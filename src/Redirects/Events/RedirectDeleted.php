<?php

declare(strict_types=1);

namespace Psyao\Kami\Redirects\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A Redirect was deleted through `DeleteRedirect`, once the write committed (ADR 0029). It carries values: the row is gone.
 */
final readonly class RedirectDeleted implements ShouldDispatchAfterCommit
{
    /** @param  Authenticatable|null  $actor  Null for imports and the CLI. */
    public function __construct(
        public string $redirectId,
        public string $site,
        public string $locale,
        public string $source,
        public ?Authenticatable $actor,
    ) {}
}
