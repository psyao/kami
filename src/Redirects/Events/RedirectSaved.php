<?php

declare(strict_types=1);

namespace Psyao\Kami\Redirects\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Psyao\Kami\Redirects\Redirect;

/**
 * A Redirect was created or changed through `SaveRedirect`, once the write committed (ADR 0029).
 */
final readonly class RedirectSaved implements ShouldDispatchAfterCommit
{
    /** @param  Authenticatable|null  $actor  Null for imports and the CLI. */
    public function __construct(
        public Redirect $redirect,
        public bool $created,
        public ?Authenticatable $actor,
    ) {}
}
