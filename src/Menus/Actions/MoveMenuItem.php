<?php

declare(strict_types=1);

namespace Psyao\Kami\Menus\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Psyao\Kami\Audit\Actions\RecordAuditEvent;
use Psyao\Kami\Menus\Events\MenuItemMoved;
use Psyao\Kami\Menus\Internal\MenuTree;
use Psyao\Kami\Menus\MenuItem;

/**
 * Gives a Menu item another rank among its siblings, the others renumbered, as the admin's up and down actions do; its Parent changes through `SaveMenuItem` (ADR 0025). It does not authorise: the caller checks the actor's `kami.menus.manage` Permission first (ADR 0011).
 */
final class MoveMenuItem
{
    /**
     * @param  int  $position  The rank wanted among its siblings, from 1; one out of range is brought back within it.
     * @param  Authenticatable|null  $actor  Null for imports and the CLI.
     */
    public function handle(MenuItem $item, int $position, ?Authenticatable $actor = null): MenuItem
    {
        $moved = DB::transaction(function () use ($item, $position, $actor): bool {
            // Read again, locked: another call may have given it another Parent since the caller read it.
            $fresh = MenuItem::query()->lockForUpdate()->find($item->id);

            if (! $fresh instanceof MenuItem) {
                return false;
            }

            $item->parent_id = $fresh->parent_id;
            $item->syncOriginalAttribute('parent_id');
            $tree = MenuTree::of($item->site, $item->menu);
            $siblings = $tree->children($item->parent_id);
            $from = array_search($item->id, $siblings, true);
            $to = max(1, min($position, count($siblings))) - 1;

            if ($from === false || $from === $to) {
                return false;
            }

            array_splice($siblings, $from, 1);
            array_splice($siblings, $to, 0, [$item->id]);
            $tree->place($siblings, $item->parent_id);
            $item->position = $to + 1;
            $item->syncOriginalAttribute('position');
            resolve(RecordAuditEvent::class)->handle('kami.menu-items.moved', $item, ['menu' => $item->menu, 'position' => $item->position], $actor);

            return true;
        });

        if ($moved) {
            event(new MenuItemMoved($item, $actor));
        }

        return $item;
    }
}
