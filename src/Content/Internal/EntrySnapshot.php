<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Sites\Internal\Site;

/**
 * An Entry with one Translation as a save would leave it, as plain arrays a cache store keeps, rebuilt in memory with nothing written (ADR 0019).
 *
 * @internal
 *
 * @phpstan-type Snapshot array{entry: array<string, mixed>, translations: list<array<string, mixed>>, rows: array<string, array<string, mixed>>, references: array<string, array<string, list<string>>>, locale: string}
 */
final class EntrySnapshot
{
    /**
     * The Entry's stored Translations and values in every Locale, with those of `SaveEntry::draft()` over them.
     *
     * @param  array{locale: string, data: array<mixed>}  $draft
     * @param  string  $readLocale  The Locale it renders in: the edited one, `'*'` being read in any.
     * @return Snapshot
     */
    public static function capture(ContentType $type, ?Entry $entry, array $draft, string $readLocale): array
    {
        ['locale' => $locale, 'data' => $data] = $draft;
        $attributes = $entry?->getAttributes() ?? ['site' => Site::current()->handle, 'type' => $type->handle()];

        if (array_key_exists('parent', $data)) {
            $attributes['parent_id'] = $data['parent'];
        }

        $translations = $entry?->translations->where('locale', '!=', $locale)->map(fn (Translation $translation): array => $translation->getAttributes())->values()->all() ?? [];
        $translation = new Translation(['locale' => $locale]);

        if ($entry instanceof Entry) {
            $translation->entry_id = $entry->id;
        }

        foreach (Translation::ATTRIBUTES as $attribute) {
            $translation->setAttribute($attribute, $data[$attribute] ?? null);
        }

        $translations[] = $translation->getAttributes();

        $rows = [];

        if ($entry instanceof Entry && $type->columnFields() !== []) {
            foreach (DB::table("kami_type_{$type->handle()}")->where('entry_id', $entry->id)->get() as $row) {
                /** @var array{locale: string} $row */
                $row = (array) $row;
                $rows[$row['locale']] = $row;
            }
        }

        $relations = array_column($type->relationFields(), 'handle');
        $references = $entry instanceof Entry && $relations !== [] ? References::stored([$entry->id], $relations)[$entry->id] ?? [] : [];

        foreach ($type->flatFields() as $field) {
            if (! array_key_exists($field->handle, $data)) {
                continue;
            }

            $scope = $type->isTranslatable($field) ? $locale : '*';

            if ($field->isReference()) {
                /** @var list<string>|null $ids */
                $ids = $data[$field->handle];
                $references[$scope][$field->handle] = $ids ?? [];
            } else {
                $rows[$scope][$field->handle] = $field->write($data[$field->handle]);
            }
        }

        return ['entry' => $attributes, 'translations' => array_values($translations), 'rows' => $rows, 'references' => $references, 'locale' => $readLocale];
    }

    /**
     * The Entry, never saved, read in its Locale; its References' targets under the public read's rule, so a Draft target is left out.
     *
     * @param  Snapshot  $snapshot
     */
    public static function rebuild(array $snapshot): Entry
    {
        $entry = (new Entry)->newFromBuilder($snapshot['entry']);
        $type = $entry->contentType() ?? throw new LogicException("No Content Type [{$entry->type}] is declared in kami.types.");

        $entry->setRelation('translations', (new Translation)->newCollection(array_map(fn (array $attributes): Translation => (new Translation)->newFromBuilder($attributes), $snapshot['translations'])));
        $entry->setFieldRows($snapshot['rows']);
        $entry->readIn($snapshot['locale']);

        $relations = $type->relationFields();
        $ids = References::split($snapshot['references'], $relations);
        $entries = array_values(array_unique($ids['entries']));
        /** @var array<string, Model> $targets */
        $targets = [...$entries === [] ? [] : Entry::query()->readIn($snapshot['locale'])->whereIn('id', $entries)->get()->keyBy('id')->all(), ...References::media($ids['media'])];

        foreach ($relations as $field) {
            $entry->setReferences($field->handle, array_map(fn (array $fields): array => $fields[$field->handle] ?? [], $snapshot['references']), $targets);
        }

        return $entry;
    }
}
