<?php

declare(strict_types=1);

namespace Psyao\Kami\Menus\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A Menu item was deleted through `DeleteMenuItem`, its children moved up one level, once the write committed (ADR 0029). It carries values: the row is gone.
 */
final readonly class MenuItemDeleted implements ShouldDispatchAfterCommit
{
    /** @param  Authenticatable|null  $actor  Null for imports and the CLI. */
    public function __construct(
        public string $itemId,
        public string $menu,
        public string $site,
        public ?Authenticatable $actor,
    ) {}
}
