<?php

declare(strict_types=1);

namespace Psyao\Kami\Menus\Internal;

use Illuminate\Support\Collection;
use Psyao\Kami\Menus\MenuItem;

/**
 * A Menu's whole tree, ids and order only, read in one query: a Menu holds tens of items, never thousands.
 *
 * @internal
 */
final readonly class MenuTree
{
    /** @param  Collection<string, MenuItem>  $items  By id, in tree order within each Parent. */
    private function __construct(private Collection $items) {}

    public static function of(string $site, string $menu): self
    {
        return new self(MenuItem::query()->where(['site' => $site, 'menu' => $menu])->orderBy('position')->orderBy('id')->get(['id', 'parent_id', 'position'])->keyBy('id'));
    }

    public function has(string $id): bool
    {
        return $this->items->has($id);
    }

    /**
     * The item's ancestors, its Parent first, with a guard against a cycle written outside the actions.
     *
     * @return list<string>
     */
    public function ancestors(string $id): array
    {
        $ancestors = [];

        while (($parent = $this->items->get($id)?->parent_id) !== null && ! in_array($parent, $ancestors, true)) {
            $ancestors[] = $id = $parent;
        }

        return $ancestors;
    }

    /** The levels the item and its descendants span: 1 for a leaf. */
    public function height(string $id, int $guard = 0): int
    {
        $children = $this->children($id);

        return $guard > 10 || $children === [] ? 1 : 1 + max(array_map(fn (string $child): int => $this->height($child, $guard + 1), $children));
    }

    /**
     * The ids of the Parent's children, in order; the root's when null.
     *
     * @return list<string>
     */
    public function children(?string $parent): array
    {
        return array_values($this->items->where('parent_id', $parent)->keys()->map(strval(...))->all());
    }

    /** The Position of a new last child of the Parent; of the root when null. */
    public function nextPosition(?string $parent): int
    {
        return $this->items->where('parent_id', $parent)->reduce(fn (int $max, MenuItem $item): int => max($max, $item->position), 0) + 1;
    }

    /**
     * Gives the ids Positions 1, 2, 3… in the given order, under that Parent, writing only the rows that change.
     *
     * @param  list<string>  $ids
     */
    public function place(array $ids, ?string $parent): void
    {
        foreach ($ids as $index => $id) {
            $item = $this->items->get($id);

            if ($item instanceof MenuItem && ($item->position !== $index + 1 || $item->parent_id !== $parent)) {
                MenuItem::query()->whereKey($id)->update(['position' => $index + 1, 'parent_id' => $parent]);
            }
        }
    }
}
