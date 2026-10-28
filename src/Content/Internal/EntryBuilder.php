<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Psyao\Kami\Content\Entry;
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

    public function eagerLoadRelations(array $models)
    {
        $models = parent::eagerLoadRelations($models);

        if ($this->locale !== null) {
            array_walk($models, fn (Entry $entry) => $entry->readIn($this->locale));
        }

        $types = resolve(ContentTypes::class);

        foreach (collect($models)->groupBy(fn (Entry $entry) => $entry->type) as $handle => $entries) {
            // An undeclared type, or one with no Field, has no table to read.
            if (($types->find((string) $handle)?->fields() ?? []) === []) {
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

        return $models;
    }
}
