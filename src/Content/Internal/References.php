<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Psyao\Kami\Content\Entry;

/**
 * The one way relation Fields reach `kami_references` (ADR 0005). The `'*'` key stands for a shared Reference's null `locale`, as in a type's table.
 *
 * @internal
 */
final class References
{
    private const string TABLE = 'kami_references';

    /**
     * The target ids in pick order.
     *
     * @param  array<string>  $entries
     * @param  array<string>  $handles
     * @return array<string, array<string, array<string, list<string>>>> By source Entry, Locale and handle.
     */
    public static function stored(array $entries, array $handles): array
    {
        $stored = [];

        // ponytail: Entry targets only; M5 reads `target_media_id` for its Media Field.
        foreach (DB::table(self::TABLE)->whereIn('source_entry_id', $entries)->whereIn('field', $handles)->whereNotNull('target_entry_id')->orderBy('position')->get() as $row) {
            /** @var object{source_entry_id: string, locale: string|null, field: string, target_entry_id: string} $row */
            $stored[$row->source_entry_id][$row->locale ?? '*'][$row->field][] = $row->target_entry_id;
        }

        return $stored;
    }

    /**
     * Rebuilds one Field's rows in one Locale; no id stores no row, which is what blank means for Fallback.
     *
     * @param  list<string>  $targets
     */
    public static function replace(string $entry, string $handle, ?string $locale, array $targets): void
    {
        DB::table(self::TABLE)->where(['source_entry_id' => $entry, 'field' => $handle, 'locale' => $locale])->delete();

        DB::table(self::TABLE)->insert(array_map(fn (string $target, int $position): array => [
            'id' => (string) Str::ulid(),
            'source_entry_id' => $entry,
            'field' => $handle,
            'locale' => $locale,
            'target_entry_id' => $target,
            'position' => $position,
        ], $targets, array_keys($targets)));
    }

    /** Removes a Field's rows in every Locale. */
    public static function clear(string $entry, string $handle): void
    {
        DB::table(self::TABLE)->where(['source_entry_id' => $entry, 'field' => $handle])->delete();
    }

    /**
     * Gives Entries their relation Fields: one query for the rows, then one Entry query per way of reading them, with its usual queries.
     *
     * @param  array<Entry>  $entries
     * @param  array<string>  $handles  A handle that is no relation Field of an Entry's type is ignored for it.
     * @param  list<string>  $nested  What the targets load in turn.
     */
    public static function load(array $entries, array $handles, array $nested = []): void
    {
        foreach (collect($entries)->groupBy(fn (Entry $entry): string => $entry->readLocale() . '|' . (int) $entry->readsUnpublished()) as $group) {
            /** @var Entry $first */
            $first = $group->first();
            $stored = self::stored($group->map(fn (Entry $entry): string => $entry->id)->all(), $handles);
            $ids = collect($stored)->flatten()->unique()->values()->all();
            $query = Entry::query()->readIn($first->readLocale())->with($nested);

            if ($first->readsUnpublished()) {
                $query->withUnpublished();
            }

            /** @var array<string, Entry> $targets */
            $targets = $ids === [] ? [] : $query->whereIn('id', $ids)->get()->keyBy('id')->all();

            foreach ($group as $entry) {
                foreach ($entry->contentType()?->relationFields() ?? [] as $field) {
                    if (in_array($field->handle, $handles, true)) {
                        $entry->setReferences($field->handle, array_map(fn (array $fields): array => $fields[$field->handle] ?? [], $stored[$entry->id] ?? []), $targets);
                    }
                }
            }
        }
    }
}
