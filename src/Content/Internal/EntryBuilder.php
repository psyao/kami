<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

use Illuminate\Database\Eloquent\Builder;
use Psyao\Kami\Content\Entry;

/**
 * Loads the Field values of the Entries it returns: one query per type present, never lazily (ADR 0006).
 *
 * @internal
 *
 * @extends Builder<Entry>
 */
final class EntryBuilder extends Builder
{
    public function eagerLoadRelations(array $models)
    {
        $models = parent::eagerLoadRelations($models);
        $types = resolve(ContentTypes::class);

        foreach (collect($models)->groupBy(fn (Entry $entry) => $entry->type) as $handle => $entries) {
            // An undeclared type, or one with no Field, has no table to read.
            if (($types->find((string) $handle)?->fields() ?? []) === []) {
                continue;
            }

            $rows = [];

            foreach (FieldRow::of((string) $handle)->newQuery()->whereIn('entry_id', $entries->map(fn (Entry $entry) => $entry->id))->get() as $row) {
                $rows[$row->entry_id][$row->locale] = $row->getAttributes();
            }

            $entries->each(fn (Entry $entry) => $entry->setFieldRows($rows[$entry->id] ?? []));
        }

        return $models;
    }
}
