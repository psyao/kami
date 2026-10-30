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
 * @phpstan-type Row array{entry_id: string, locale: string, path: string}
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
                    $ids = $entries->pluck('id');
                    $stored = Path::query()->where(['site' => $site->handle, 'status' => 200])->whereIn('entry_id', $ids)
                        ->whereIn('locale', $site->locales)->get()->keyBy(fn (Path $path): string => $this->key($path->entry_id, $path->locale));
                    $expected = $this->expected($site, $type, $ids);

                    // Deleted first: a freed address may be the one another row moves to.
                    $gone = $stored->diffKeys($expected);
                    $counts['deleted'] += $gone->count();

                    if ($write && $gone->isNotEmpty()) {
                        Path::query()->whereKey($gone->modelKeys())->delete();
                    }

                    foreach ($expected as $key => $row) {
                        $current = $stored->get($key);

                        if ($current?->path === $row['path']) {
                            continue;
                        }

                        if (! $write || $this->write($site, $row, $current)) {
                            $counts[$current === null ? 'created' : 'rewritten']++;
                        } else {
                            $pending[] = ['row' => $row, 'current' => $current];
                        }
                    }
                });
        }

        // ponytail: one more pass settles a row that waited for an address a later row freed; a swap between two Entries stays a clash.
        $clashes = [];

        foreach ($pending as ['row' => $row, 'current' => $current]) {
            if ($this->write($site, $row, $current)) {
                $counts[$current === null ? 'created' : 'rewritten']++;
            } else {
                $holder = Path::query()->where(['site' => $site->handle, 'locale' => $row['locale'], 'path' => $row['path']])->value('entry_id');
                $clashes[] = [...$row, 'holder' => is_string($holder) ? $holder : null];
            }
        }

        return [...$counts, 'clashes' => $clashes];
    }

    /**
     * The 200 rows the batch's Translations call for, keyed like the stored ones: a `'*'` Translation answers in every Locale.
     *
     * @param  Collection<int, mixed>  $ids
     * @return Collection<string, Row>
     */
    private function expected(Site $site, ContentType $type, Collection $ids): Collection
    {
        $rows = [];

        foreach (Translation::query()->whereIn('entry_id', $ids)->whereNotNull('slug')->get() as $translation) {
            $locales = $type->isTranslatable()
                ? array_intersect([$translation->locale], $site->locales)
                : ($translation->locale === '*' ? $site->locales : []);

            foreach ($locales as $locale) {
                $rows[$this->key($translation->entry_id, $locale)] = ['entry_id' => $translation->entry_id, 'locale' => $locale, 'path' => Path::of($type, (string) $translation->slug)];
            }
        }

        return collect($rows);
    }

    private function key(?string $entry, string $locale): string
    {
        return "{$entry} {$locale}";
    }

    /**
     * Each write stands alone: wrapped in a transaction, a caught clash would abort every later statement on PostgreSQL.
     *
     * @param  Row  $row
     */
    private function write(Site $site, array $row, ?Path $current): bool
    {
        try {
            // ponytail: rewritten in place, so the former address answers 404; M7 (Redirects) keeps it as a 301 once published.
            $current instanceof Path
                ? $current->update(['path' => $row['path']])
                : Path::query()->create(['site' => $site->handle, 'status' => 200, ...$row]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
