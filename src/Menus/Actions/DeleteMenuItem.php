<?php

declare(strict_types=1);

namespace Psyao\Kami\Menus\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Psyao\Kami\Audit\Actions\RecordAuditEvent;
use Psyao\Kami\Menus\Events\MenuItemDeleted;
use Psyao\Kami\Menus\Internal\MenuTree;
use Psyao\Kami\Menus\MenuItem;

/**
 * Deletes a Menu item; its children move up one level, in its place and in their order, so no subtree is dropped silently (ADR 0025). `DeleteEntry` calls it for the items leading to the Entry. It does not authorise: the caller checks the actor's `kami.menus.manage` Permission first (ADR 0011).
 */
final class DeleteMenuItem
{
    /** @param  Authenticatable|null  $actor  Null for imports and the CLI. */
    public function handle(MenuItem $item, ?Authenticatable $actor = null): void
    {
        $deleted = DB::transaction(function () use ($item, $actor): bool {
            // Read again, locked: another call may have moved it, or deleted it, since the caller read it.
            $item = MenuItem::query()->lockForUpdate()->find($item->id);

            if (! $item instanceof MenuItem) {
                return false;
            }

            $tree = MenuTree::of($item->site, $item->menu);
            $siblings = $tree->children($item->parent_id);
            $at = array_search($item->id, $siblings, true);
            $children = $tree->children($item->id);
            // Among its siblings unless a write outside the actions broke the tree: its children then go last.
            $at === false ? array_push($siblings, ...$children) : array_splice($siblings, $at, 1, $children);
            $tree->place($siblings, $item->parent_id);
            $item->delete();
            resolve(RecordAuditEvent::class)->handle('kami.menu-items.deleted', $item, ['menu' => $item->menu], $actor);

            return true;
        });

        if ($deleted) {
            event(new MenuItemDeleted($item->id, $item->menu, $item->site, $actor));
        }
    }
}
