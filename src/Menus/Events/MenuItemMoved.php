<?php

declare(strict_types=1);

namespace Psyao\Kami\Menus\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Psyao\Kami\Menus\MenuItem;

/**
 * A Menu item took another rank among its siblings through `MoveMenuItem`, once the write committed (ADR 0029). A call that changes nothing dispatches nothing.
 */
final readonly class MenuItemMoved implements ShouldDispatchAfterCommit
{
    /** @param  Authenticatable|null  $actor  Null for imports and the CLI. */
    public function __construct(
        public MenuItem $item,
        public ?Authenticatable $actor,
    ) {}
}
