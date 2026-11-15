<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal\MenuItemResource;

use Filament\Resources\Pages\ManageRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Psyao\Kami\Admin\Internal\EntryResource;
use Psyao\Kami\Admin\Internal\MenuItemResource;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Menus\Internal\Menus;
use Psyao\Kami\Menus\Internal\MenuTree;
use Psyao\Kami\Menus\MenuItem;
use Psyao\Kami\Sites\Internal\Site;

/**
 * A Menu's whole tree on one page, items created and edited in a modal.
 *
 * @internal
 */
final class ManageMenuItems extends ManageRecords
{
    #[\Override]
    protected static string $resource = MenuItemResource::class;

    /** Read once per request, and again with each table query, so after a write as well: never kept between requests. */
    private ?MenuTree $tree = null;

    /** @var array<string, int>|null */
    private ?array $levels = null;

    /** @var Collection<string, MenuItem>|null */
    private ?Collection $items = null;

    /** @var array<string, string>|null */
    private ?array $titles = null;

    /**
     * The tree's items in tree order: a Menu holds tens of items, so one more query for their order, and one `id = ?` term per item, which every engine sorts.
     *
     * @param  Builder<MenuItem>  $query
     * @return Builder<MenuItem>
     */
    public function inTreeOrder(Builder $query): Builder
    {
        $this->tree = $this->levels = $this->items = $this->titles = null;
        $ids = array_keys($this->levels());
        $query->whereKey($ids);

        foreach ($ids as $id) {
            $query->orderByRaw('id = ? desc', [$id]);
        }

        return $query;
    }

    public function level(MenuItem $item): int
    {
        return $this->levels()[$item->id] ?? 1;
    }

    /** Its label in the default Locale, or what the front falls back to there. */
    public function label(MenuItem $item): string
    {
        return $item->label[Site::current()->defaultLocale()] ?? $this->target($item);
    }

    public function target(MenuItem $item): string
    {
        if ($item->entry_id !== null) {
            $this->titles ??= EntryResource::pickerTitles(array_values(array_filter($this->items()->pluck('entry_id')->all())), $this);

            return $this->titles[$item->entry_id] ?? $item->entry_id;
        }

        if ($item->archive !== null) {
            return (string) __('kami::admin.menus.archive_of', ['type' => resolve(ContentTypes::class)->find($item->archive)?->pluralLabel() ?? $item->archive]);
        }

        $url = $item->url ?? [];

        return $url[Site::current()->defaultLocale()] ?? array_first($url) ?? '';
    }

    /** Its rank among its siblings, from 1, as `MoveMenuItem` reads it: a Parent change leaves gaps in the stored Positions. */
    public function rank(MenuItem $item): int
    {
        return (int) array_search($item->id, $this->tree()->children($item->parent_id), true) + 1;
    }

    /** Whether a step up (-1) or down (1) stays among its siblings. */
    public function canMove(MenuItem $item, int $step): bool
    {
        return isset($this->tree()->children($item->parent_id)[$this->rank($item) - 1 + $step]);
    }

    /**
     * The items the edited one may go under, in tree order and indented by level: neither itself nor its subtree, nor one that would nest it past the Menu's depth.
     * Its current Parent stays offered, so an item a lowered `depth` left too deep keeps saving.
     *
     * @return array<string, string>
     */
    public function parentOptions(?MenuItem $edited): array
    {
        $depth = Menus::depth(MenuItemResource::menu());
        $height = $edited instanceof MenuItem ? $this->tree()->height($edited->id) : 1;
        $options = [];

        foreach ($this->levels() as $id => $level) {
            $item = $this->items()->get($id);
            $inside = $edited instanceof MenuItem && ($id === $edited->id || in_array($edited->id, $this->tree()->ancestors($id), true));

            if ($item instanceof MenuItem && ! $inside && ($level + $height <= $depth || $id === $edited?->parent_id)) {
                $options[$id] = str_repeat('— ', $level - 1) . $this->label($item);
            }
        }

        return $options;
    }

    protected function getHeaderActions(): array
    {
        return [MenuItemResource::createAction()];
    }

    private function tree(): MenuTree
    {
        return $this->tree ??= MenuTree::of(Site::current()->handle, MenuItemResource::menu());
    }

    /** @return array<string, int> */
    private function levels(): array
    {
        return $this->levels ??= $this->tree()->levels();
    }

    /** @return Collection<string, MenuItem> The Menu's items by id, for labels and titles. */
    private function items(): Collection
    {
        return $this->items ??= MenuItemResource::getEloquentQuery()->get()->keyBy('id');
    }
}
