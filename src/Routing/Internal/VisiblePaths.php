<?php

declare(strict_types=1);

namespace Psyao\Kami\Routing\Internal;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Internal\Translation;
use Psyao\Kami\Sites\Internal\Site;

/**
 * Visibility through ancestors (ADR 0019). A hierarchical Path holds its ancestors' Paths, so each ancestor's 200 row sits at one of its prefixes:
 * one query over the prefixes reads them all, whatever the depth, with no recursive CTE. Collections that build URLs preload a batch at once.
 *
 * @internal
 *
 * @phpstan-type Row object{locale: string, path: string, status: int|string, entry_id: string|null, parent_id: string|null, deleted_at: string|null, visible: int|string}
 */
final class VisiblePaths
{
    /**
     * The rows at these Paths in these Locales, whatever their status, each with its Entry's Parent and whether its Translation there is visible.
     *
     * @param  list<string>  $locales
     * @param  list<string>  $paths
     * @return Collection<int, Row>
     */
    public static function at(string $site, array $locales, array $paths): Collection
    {
        /** @var Collection<int, Row> */
        return DB::table('kami_paths')
            ->leftJoin('kami_entries', 'kami_entries.id', '=', 'kami_paths.entry_id')
            ->where('kami_paths.site', $site)
            ->whereIn('kami_paths.locale', $locales)
            ->whereIn('kami_paths.path', $paths)
            ->select(['kami_paths.locale', 'kami_paths.path', 'kami_paths.status', 'kami_paths.entry_id', 'kami_entries.parent_id', 'kami_entries.deleted_at'])
            // A count, as the Visibility scope reads it (#222), in the row's Locale or the `'*'` Translation.
            ->selectSub(fn (Builder $translations): Builder => $translations->from('kami_translations')->selectRaw('count(*)')
                ->whereColumn('kami_translations.entry_id', 'kami_paths.entry_id')
                ->where(fn (Builder $locale): Builder => $locale->whereColumn('kami_translations.locale', 'kami_paths.locale')->orWhere('kami_translations.locale', '*'))
                ->where('kami_translations.status', Translation::PUBLISHED)
                ->where('kami_translations.published_at', '<=', now()), 'visible')
            ->get();
    }

    /**
     * A Path and the Paths above it: `a/b/c`, `a/b`, `a`.
     *
     * @return list<string>
     */
    public static function prefixes(string $path): array
    {
        $prefixes = [$path];

        while (($cut = strrpos($path, '/')) !== false) {
            $prefixes[] = $path = substr($path, 0, $cut);
        }

        return $prefixes;
    }

    /**
     * The current rows among these in a Locale, by Entry id: what `reachable()` walks.
     *
     * @param  Collection<int, Row>  $rows
     * @return Collection<string, Row>
     */
    public static function index(Collection $rows, string $locale): Collection
    {
        /** @var Collection<string, Row> */
        return $rows->filter(fn (object $row): bool => $row->locale === $locale && (int) $row->status === 200)->keyBy('entry_id');
    }

    /**
     * Whether every ancestor from `$parent` up has a row in `$current`, out of the Trash and visible in its Locale. One missing, as before
     * `kami:install` rewrites a flat Path, reads as not visible; a cycle concurrent moves could close too.
     *
     * @param  Collection<string, Row>  $current  From `index()`.
     */
    public static function reachable(Collection $current, ?string $parent): bool
    {
        $seen = [];

        while ($parent !== null) {
            $row = $current->get($parent);

            if ($row === null || isset($seen[$parent]) || $row->deleted_at !== null || (int) $row->visible === 0) {
                return false;
            }

            $seen[$parent] = true;
            $parent = $row->parent_id;
        }

        return true;
    }

    /**
     * Gives each nested Entry its Path in every Locale of the Site, null where it has none or an ancestor is not visible: what `url()` reads.
     * Two queries for the whole batch.
     *
     * @param  iterable<array-key, Entry>  $entries
     */
    public static function preload(iterable $entries): void
    {
        $site = Site::current();
        $entries = collect($entries)->filter(fn (Entry $entry): bool => $entry->parent_id !== null)->keyBy('id');

        if ($entries->isEmpty()) {
            return;
        }

        $own = Path::query()->where(['site' => $site->handle, 'status' => 200])->whereIn('entry_id', $entries->keys())->whereIn('locale', $site->locales)->get(['entry_id', 'locale', 'path']);
        $above = array_values(array_unique($own->flatMap(fn (Path $path): array => array_slice(self::prefixes($path->path), 1))->all()));
        /** @var Collection<int, Row> $ancestors */
        $ancestors = $above === [] ? new Collection : self::at($site->handle, $site->locales, $above);

        $current = array_map(fn (string $locale): Collection => self::index($ancestors, $locale), array_combine($site->locales, $site->locales));
        $own = $own->groupBy('entry_id');

        foreach ($entries as $id => $entry) {
            $paths = array_fill_keys($site->locales, null);

            foreach ($own->get($id, []) as $path) {
                $paths[$path->locale] = self::reachable($current[$path->locale], $entry->parent_id) ? $path->path : null;
            }

            $entry->setVisiblePaths($paths);
        }
    }
}
