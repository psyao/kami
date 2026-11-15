<?php

declare(strict_types=1);

namespace Psyao\Kami\Menus\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Psyao\Kami\Menus\MenuItem;

/**
 * A Menu item was created or changed through `SaveMenuItem`, once the write committed (ADR 0029).
 */
final readonly class MenuItemSaved implements ShouldDispatchAfterCommit
{
    /** @param  Authenticatable|null  $actor  Null for imports and the CLI. */
    public function __construct(
        public MenuItem $item,
        public bool $created,
        public ?Authenticatable $actor,
    ) {}
}
