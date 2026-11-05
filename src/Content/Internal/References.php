<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Fields\Field;
use Psyao\Kami\Content\Fields\Media as MediaField;
use Psyao\Kami\Media\Media;

/**
 * The one way relation and Media Fields reach `kami_references` (ADR 0005). The `'*'` key stands for a shared Reference's null `locale`, as in a type's table.
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

        foreach (DB::table(self::TABLE)->whereIn('source_entry_id', $entries)->whereIn('field', $handles)->orderBy('position')->get() as $row) {
            /** @var object{source_entry_id: string, locale: string|null, field: string, target_entry_id: string|null, target_media_id: string|null} $row */
            $stored[$row->source_entry_id][$row->locale ?? '*'][$row->field][] = (string) ($row->target_entry_id ?? $row->target_media_id);
        }

        return $stored;
    }

    /**
     * Rebuilds one Field's rows in one Locale; no id stores no row, which is what blank means for Fallback.
     *
     * @param  list<string>  $targets
     */
    public static function replace(string $entry, Field $field, ?string $locale, array $targets): void
    {
        DB::table(self::TABLE)->where(['source_entry_id' => $entry, 'field' => $field->handle, 'locale' => $locale])->delete();

        DB::table(self::TABLE)->insert(array_map(fn (string $target, int $position): array => [
            'id' => (string) Str::ulid(),
            'source_entry_id' => $entry,
            'field' => $field->handle,
            'locale' => $locale,
            $field instanceof MediaField ? 'target_media_id' : 'target_entry_id' => $target,
            'position' => $position,
        ], $targets, array_keys($targets)));
    }

    /**
     * A relation or Media Field's rule, on the Field itself so an error names the Field, not a row: distinct ids, all linkable, in one query.
     *
     * @param  Closure(): Builder<covariant Model>  $linkable
     */
    public static function targetsRule(Closure $linkable): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($linkable): void {
            if (! is_array($value)) {
                return;
            }

            $ids = array_filter($value, is_string(...));

            if (count(array_unique($ids)) !== count($value)) {
                $fail(__(count($ids) === count($value) ? 'validation.distinct' : 'validation.exists'));

                return;
            }

            if ($linkable()->whereKey($ids)->count() !== count($ids)) {
                $fail(__('validation.exists'));
            }
        };
    }

    /**
     * One Entry's stored ids, by kind of target: what the relation Fields link, what the Media Fields link.
     *
     * @param  array<string, array<string, list<string>>>  $byLocale  By Locale and handle, as `stored()` gives an Entry's.
     * @param  array<Field>  $fields  Its type's relation and Media Fields to read.
     * @return array{entries: list<string>, media: list<string>}
     */
    public static function split(array $byLocale, array $fields): array
    {
        $split = ['entries' => [], 'media' => []];

        foreach ($fields as $field) {
            foreach ($byLocale as $handles) {
                array_push($split[$field instanceof MediaField ? 'media' : 'entries'], ...$handles[$field->handle] ?? []);
            }
        }

        return $split;
    }

    /**
     * The Media items by id, with their Translations: two queries, none for no id.
     *
     * @param  array<string>  $ids
     * @return array<string, Media>
     */
    public static function media(array $ids): array
    {
        $media = [];

        foreach ($ids === [] ? [] : Media::query()->whereKey(array_values(array_unique($ids)))->get() as $item) {
            $media[$item->id] = $item;
        }

        return $media;
    }

    /** Removes a Field's rows in every Locale. */
    public static function clear(string $entry, string $handle): void
    {
        DB::table(self::TABLE)->where(['source_entry_id' => $entry, 'field' => $handle])->delete();
    }

    /**
     * What links to an Entry through a relation Field, in any state, out of the Trash: "used by".
     *
     * @return Builder<Entry>
     */
    public static function sources(string $target): Builder
    {
        return Entry::query()->withUnpublished()->whereIn('id', DB::table(self::TABLE)->where('target_entry_id', $target)->select('source_entry_id'))->orderBy('id');
    }

    /**
     * Gives Entries their relation and Media Fields: one query for the rows, then one Entry query per way of reading them, with its usual queries, and one for the Media items with their Translations.
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
            $fields = $group->mapWithKeys(fn (Entry $entry): array => [$entry->id => array_filter($entry->contentType()?->relationFields() ?? [], fn (Field $field): bool => in_array($field->handle, $handles, true))]);
            $ids = $fields->map(fn (array $read, string $id): array => self::split($stored[$id] ?? [], $read));
            $entryIds = $ids->flatMap(fn (array $split): array => $split['entries'])->unique()->values()->all();
            $query = Entry::query()->readIn($first->readLocale())->with($nested);

            if ($first->readsUnpublished()) {
                $query->withUnpublished();
            }

            // Two ULIDs never meet, so Entries and Media items share one map.
            /** @var array<string, Model> $targets */
            $targets = [...$entryIds === [] ? [] : $query->whereIn('id', $entryIds)->get()->keyBy('id')->all(), ...self::media($ids->flatMap(fn (array $split): array => $split['media'])->all())];

            foreach ($group as $entry) {
                foreach ($fields[$entry->id] ?? [] as $field) {
                    $entry->setReferences($field->handle, array_map(fn (array $byHandle): array => $byHandle[$field->handle] ?? [], $stored[$entry->id] ?? []), $targets);
                }
            }
        }
    }
}
