<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal\RedirectResource;

use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Collection;
use Psyao\Kami\Admin\Internal\EntryResource;
use Psyao\Kami\Admin\Internal\RedirectResource;
use Psyao\Kami\Redirects\Redirect;

/**
 * The Redirects in one table, created and edited in a modal.
 *
 * @internal
 */
final class ManageRedirects extends ManageRecords
{
    #[\Override]
    protected static string $resource = RedirectResource::class;

    /** @var array<string, string|null> The titles of the shown Redirects' target Entries, read once per request: never kept between requests. */
    private array $titles = [];

    /** Its target Entry's title, its address, or nothing for a 410. */
    public function target(Redirect $redirect): ?string
    {
        if ($redirect->entry_id === null) {
            return $redirect->target;
        }

        if (! array_key_exists($redirect->entry_id, $this->titles)) {
            $records = $this->getTableRecords();
            $ids = collect($records instanceof Collection ? $records : $records->items())->pluck('entry_id')->filter()->unique()->values()->all();
            /** @var list<string> $ids */
            $this->titles = EntryResource::pickerTitles($ids, $this) + array_fill_keys([...$ids, $redirect->entry_id], null);
        }

        return $this->titles[$redirect->entry_id] ?? $redirect->entry_id;
    }

    protected function getHeaderActions(): array
    {
        return [RedirectResource::createAction()];
    }
}
