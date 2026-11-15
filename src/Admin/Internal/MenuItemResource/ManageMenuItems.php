<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal\MenuItemResource;

use Filament\Resources\Pages\ManageRecords;
use Illuminate\Database\Eloquent\Builder;
use Psyao\Kami\Admin\Internal\EntryResource;
use Psyao\Kami\Admin\Internal\MenuItemResource;
use Psyao\Kami\Content\Internal\ContentTypes;
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

    /** Read with the table's query, so after a write as well: never kept between requests. */
    private ?MenuTree $tree = null;

    /** @var array<string, int> */
    private array $levels = [];

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
        $this->tree = MenuTree::of(Site::current()->handle, MenuItemResource::menu());
        $this->levels = $this->tree->levels();
        $this->titles = null;
        $ids = array_keys($this->levels);

        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        $query->whereKey($ids);

        foreach ($ids as $id) {
            $query->orderByRaw('id = ? desc', [$id]);
        }

        return $query;
    }

    public function level(MenuItem $item): int
    {
        return $this->levels[$item->id] ?? 1;
    }

    /** Its label in the default Locale, or what the front falls back to there. */
    public function label(MenuItem $item): string
    {
        return $item->label[Site::current()->defaultLocale()] ?? $this->target($item);
    }

    public function target(MenuItem $item): string
    {
        if ($item->entry_id !== null) {
            $this->titles ??= EntryResource::pickerTitles(array_values(array_filter(MenuItemResource::getEloquentQuery()->pluck('entry_id')->all())), $this);

            return $this->titles[$item->entry_id] ?? $item->entry_id;
        }

        if ($item->archive !== null) {
            return (string) __('kami::admin.menus.archive_of', ['type' => resolve(ContentTypes::class)->find($item->archive)?->pluralLabel() ?? $item->archive]);
        }

        $url = $item->url ?? [];

        return $url[Site::current()->defaultLocale()] ?? array_first($url) ?? '';
    }

    /** Whether a step up (-1) or down (1) stays among its siblings. */
    public function canMove(MenuItem $item, int $step): bool
    {
        $siblings = $this->tree?->children($item->parent_id) ?? [];
        $at = array_search($item->id, $siblings, true);

        return $at !== false && isset($siblings[$at + $step]);
    }

    /**
     * Every item of the Menu but the edited one and its subtree, in tree order, indented by level.
     *
     * @return array<string, string>
     */
    public function parentOptions(?MenuItem $edited): array
    {
        $tree = MenuTree::of(Site::current()->handle, MenuItemResource::menu());
        $items = MenuItemResource::getEloquentQuery()->get()->keyBy('id');
        $options = [];

        foreach ($tree->levels() as $id => $level) {
            $item = $items->get($id);

            if ($item instanceof MenuItem && (! $edited instanceof MenuItem || ($id !== $edited->id && ! in_array($edited->id, $tree->ancestors($id), true)))) {
                $options[$id] = str_repeat('— ', $level - 1) . $this->label($item);
            }
        }

        return $options;
    }

    protected function getHeaderActions(): array
    {
        return [MenuItemResource::createAction()];
    }
}
