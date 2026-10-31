<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Fields\Field;
use Psyao\Kami\Content\Fields\RelationField;
use Psyao\Kami\Sites\Internal\Site;

/**
 * Loads the Field values of the Entries it returns: one query per type present, never lazily (ADR 0006).
 *
 * @internal
 *
 * @extends Builder<Entry>
 */
final class EntryBuilder extends Builder
{
    /** The Locale its Entries are checked and read in; the current Locale when null. */
    private ?string $locale = null;

    public function readIn(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function locale(): string
    {
        return $this->locale ?? Site::current()->currentLocale();
    }

    /** It hydrates without `eagerLoadRelations()`, so its Entries would read `null` for every stored Field value. */
    public function cursor(): never
    {
        throw new LogicException('Entries load their Field values in batches: use lazy() or chunk(), not cursor().');
    }

    public function eagerLoadRelations(array $models)
    {
        // A relation Field's handle is no method: it stays away from Eloquent, which would look for one.
        $requested = $this->eagerLoad;
        $handles = collect($models)->flatMap(fn (Entry $entry): array => $entry->contentType()?->flatFields() ?? [])
            ->filter(fn (Field $field): bool => $field instanceof RelationField && array_key_exists($field->handle, $requested))
            ->map(fn (Field $field): string => $field->handle)->unique()->values()->all();
        $this->eagerLoad = array_diff_key($requested, array_flip($handles));
        $models = parent::eagerLoadRelations($models);
        $this->eagerLoad = $requested;

        if ($this->locale !== null) {
            array_walk($models, fn (Entry $entry) => $entry->readIn($this->locale));
        }

        if (in_array(Visibility::class, $this->removedScopes(), true)) {
            array_walk($models, fn (Entry $entry) => $entry->readUnpublished());
        }

        $types = resolve(ContentTypes::class);

        foreach (collect($models)->groupBy(fn (Entry $entry): string => $entry->contentType()?->handle() ?? '') as $handle => $entries) {
            // An undeclared type, a partial select or a type with no Field has no table to read.
            if (($types->find((string) $handle)?->columnFields() ?? []) === []) {
                continue;
            }

            $rows = [];

            foreach (DB::table("kami_type_{$handle}")->whereIn('entry_id', $entries->map(fn (Entry $entry) => $entry->id))->get() as $row) {
                /** @var array{entry_id: string, locale: string} $row */
                $row = (array) $row;
                $rows[$row['entry_id']][$row['locale']] = $row;
            }

            $entries->each(fn (Entry $entry) => $entry->setFieldRows($rows[$entry->id] ?? []));
        }

        if ($handles !== []) {
            References::load($models, $handles, array_keys(array_merge(...array_map($this->relationsNestedUnder(...), $handles))));
        }

        return $models;
    }
}
