<?php

declare(strict_types=1);

namespace Psyao\Kami\Routing\Internal;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Content\Internal\Translation;
use Psyao\Kami\Sites\Internal\Site;

/**
 * Brings the 200 rows of `kami_paths` in line with the Translations and the declarations, after a Prefix change or a new
 * Locale (ADR 0008). Entries go in batches, so memory stays flat whatever the volume: run it from the CLI, never in a request.
 * Entries of an undeclared type and rows of an undeclared Locale are left as they are, for `kami:doctor` to report.
 *
 * @internal
 *
 * @phpstan-type Row array{entry_id: string, locale: string, path: string, wasPublic: bool}
 */
final readonly class SyncPaths
{
    private const int BATCH = 500;

    public function __construct(private ContentTypes $types) {}

    /**
     * With `$write` false, counts the differences and changes nothing.
     *
     * @return array{created: int, rewritten: int, deleted: int, clashes: list<array{entry_id: string, locale: string, path: string, holder: string|null}>}
     */
    public function __invoke(bool $write = true): array
    {
        // ponytail: the `default` Site only, as `Site::current()`; V1 multisite syncs every Site's.
        $site = Site::current();
        $counts = ['created' => 0, 'rewritten' => 0, 'deleted' => 0];
        /** @var list<array{row: Row, current: Path|null}> $pending */
        $pending = [];

        foreach ($this->types->all() as $type) {
            DB::table('kami_entries')->where(['site' => $site->handle, 'type' => $type->handle()])->select('id')
                ->chunkById(self::BATCH, function (Collection $entries) use ($site, $type, $write, &$counts, &$pending): void {
                    /** @var list<string> $ids */
                    $ids = $entries->pluck('id')->all();
                    ['gone' => $gone, 'changed' => $changed] = $this->diff($site, $type, $ids, $site->locales);
                    $counts['deleted'] += $gone->count();

                    // Deleted first: a freed address may be the one another row moves to.
                    if ($write && $gone->isNotEmpty()) {
                        Path::query()->whereKey($gone->modelKeys())->delete();
                    }

                    foreach ($changed as ['row' => $row, 'current' => $current]) {
                        if (! $write || $this->write($site, $row, $current)) {
                            $counts[$current === null ? 'created' : 'rewritten']++;
                        } else {
                            $pending[] = ['row' => $row, 'current' => $current];
                        }
                    }
                });
        }

        // ponytail: one more pass settles a row that waited for an address a later row freed; a swap between two Entries stays a clash, reported; V1 settles swaps if a site meets one.
        $clashes = [];

        foreach ($pending as ['row' => $row, 'current' => $current]) {
            if ($this->write($site, $row, $current)) {
                $counts[$current === null ? 'created' : 'rewritten']++;
            } else {
                $holder = Path::query()->where(['site' => $site->handle, 'locale' => $row['locale'], 'path' => $row['path']])->value('entry_id');
                $clashes[] = ['entry_id' => $row['entry_id'], 'locale' => $row['locale'], 'path' => $row['path'], 'holder' => is_string($holder) ? $holder : null];
            }
        }

        return [...$counts, 'clashes' => $clashes];
    }

    /**
     * Brings the 200 rows of some Entries of one type in line within the caller's transaction: `SaveEntry` after a change of slug or Parent,
     * the Entry and its descendants. A clash throws, rolling the caller's write back.
     *
     * @param  non-empty-list<string>  $ids  The Entry written first.
     * @param  list<string>  $locales  The Locales whose rows may change.
     * @param  array<string, bool>  $wasPublic  By Locale, the written Translation's `wasPublic()` read before the write: after it, a first publication would read as public.
     *
     * @throws UniqueConstraintViolationException
     */
    public function sync(Site $site, ContentType $type, array $ids, array $locales, array $wasPublic = []): void
    {
        ['gone' => $gone, 'changed' => $changed] = $this->diff($site, $type, $ids, $locales);

        Path::query()->whereKey($gone->modelKeys())->delete();

        foreach ($changed as ['row' => $row, 'current' => $current]) {
            if ($row['entry_id'] === $ids[0] && isset($wasPublic[$row['locale']])) {
                $row['wasPublic'] = $wasPublic[$row['locale']];
            }

            $this->move($site, $row, $current);
        }
    }

    /**
     * The stored 200 rows the Translations no longer call for, and the rows to write, in the given Locales.
     *
     * @param  list<string>  $ids
     * @param  list<string>  $locales
     * @return array{gone: \Illuminate\Database\Eloquent\Collection<string, Path>, changed: list<array{row: Row, current: Path|null}>}
     */
    private function diff(Site $site, ContentType $type, array $ids, array $locales): array
    {
        $stored = Path::query()->where(['site' => $site->handle, 'status' => 200])->whereIn('entry_id', $ids)
            ->whereIn('locale', $locales)->get()->keyBy(fn (Path $path): string => $this->key($path->entry_id, $path->locale));
        $expected = $this->expected($site, $type, $ids)->filter(fn (array $row): bool => in_array($row['locale'], $locales, true));
        $changed = [];

        foreach ($expected as $key => $row) {
            $current = $stored->get($key);

            if ($current?->path !== $row['path']) {
                $changed[] = ['row' => $row, 'current' => $current];
            }
        }

        return ['gone' => $stored->diffKeys($expected), 'changed' => $changed];
    }

    /**
     * The 200 rows the Entries' Translations call for, keyed like the stored ones: a `'*'` Translation answers in every Locale.
     * A hierarchical type's Path holds its ancestors' slugs in the same Locale, and is none while one of them has no slug there.
     *
     * @param  list<string>  $ids
     * @return Collection<string, Row>
     */
    private function expected(Site $site, ContentType $type, array $ids): Collection
    {
        $tree = $type->isHierarchical() ? $this->tree($ids) : [];
        $rows = [];

        foreach (Translation::query()->whereIn('entry_id', $ids)->get() as $translation) {
            $slug = $type->isHierarchical() ? $this->slugs($tree, $translation->entry_id, $translation->locale) : $translation->slug;
            $path = Path::of($type, $slug);

            if ($path === null) {
                continue;
            }

            $locales = $type->isTranslatable()
                ? array_intersect([$translation->locale], $site->locales)
                : ($translation->locale === '*' ? $site->locales : []);

            foreach ($locales as $locale) {
                $rows[$this->key($translation->entry_id, $locale)] = ['entry_id' => $translation->entry_id, 'locale' => $locale, 'path' => $path, 'wasPublic' => $translation->wasPublic()];
            }
        }

        return collect($rows);
    }

    /**
     * The Entries and all their ancestors, each with its Parent and its slug by Locale: one walk up per level, so a batch costs its depth in queries.
     *
     * @param  list<string>  $ids
     * @return array<string, array{parent: string|null, slugs: array<string, string|null>}>
     */
    private function tree(array $ids): array
    {
        $tree = [];

        while ($ids !== []) {
            /** @var array<string, string|null> $parents */
            $parents = DB::table('kami_entries')->whereIn('id', $ids)->pluck('parent_id', 'id')->all();

            foreach ($parents as $id => $parent) {
                $tree[$id] = ['parent' => $parent, 'slugs' => []];
            }

            foreach (DB::table('kami_translations')->whereIn('entry_id', $ids)->get(['entry_id', 'locale', 'slug']) as $translation) {
                /** @var object{entry_id: string, locale: string, slug: string|null} $translation */
                if (isset($tree[$translation->entry_id])) {
                    $tree[$translation->entry_id]['slugs'][$translation->locale] = $translation->slug;
                }
            }

            $ids = array_values(array_diff(array_filter($parents), array_keys($tree)));
        }

        return $tree;
    }

    /**
     * The slugs from the root down to the Entry in a Locale, joined by `/`; null while one is missing, or at a cycle concurrent moves could close.
     *
     * @param  array<string, array{parent: string|null, slugs: array<string, string|null>}>  $tree
     */
    private function slugs(array $tree, ?string $id, string $locale): ?string
    {
        $slugs = [];

        while ($id !== null) {
            $slug = $tree[$id]['slugs'][$locale] ?? null;

            if ($slug === null || array_key_exists($id, $slugs)) {
                return null;
            }

            $slugs[$id] = $slug;
            $id = $tree[$id]['parent'];
        }

        return implode('/', array_reverse($slugs));
    }

    private function key(?string $entry, string $locale): string
    {
        return "{$entry} {$locale}";
    }

    /**
     * Each write stands alone, in a transaction of its own: in one around them all, a caught clash would abort every later statement on PostgreSQL.
     *
     * @param  Row  $row
     */
    private function write(Site $site, array $row, ?Path $current): bool
    {
        try {
            $this->move($site, $row, $current);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    /**
     * One row to its new address: rewritten in place when never public, kept as a 301 to the Entry otherwise (ADR 0008).
     *
     * @param  Row  $row
     *
     * @throws UniqueConstraintViolationException
     */
    private function move(Site $site, array $row, ?Path $current): void
    {
        DB::transaction(function () use ($site, $row, $current): void {
            // A new Path that lands on a Redirect or a 410 replaces it (ADR 0008); kept when the write clashes.
            Path::query()->where(['site' => $site->handle, 'locale' => $row['locale'], 'path' => $row['path']])->where('status', '!=', 200)->delete();

            if ($current instanceof Path && ! $row['wasPublic']) {
                $current->update(['path' => $row['path']]);

                return;
            }

            if ($current instanceof Path) {
                // A once-public address stays, as a 301 to the Entry (ADR 0008); by query, as a clash rolls it back while the model would keep it.
                Path::query()->whereKey($current->id)->update(['status' => 301]);
            }

            Path::query()->create(['site' => $site->handle, 'locale' => $row['locale'], 'path' => $row['path'], 'entry_id' => $row['entry_id'], 'status' => 200]);
        });
    }
}
