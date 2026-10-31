<?php

declare(strict_types=1);

namespace Psyao\Kami\Content;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Query\Builder;

/** An Entry of a Taxonomy. */
class Term extends Entry
{
    /**
     * The Entries linking it through any relation Field, once each: visible ones in the current Locale, unless the query says `inLocale()` or `withUnpublished()`.
     *
     * @return BelongsToMany<Entry, $this>
     */
    public function entries(): BelongsToMany
    {
        return $this->belongsToMany(Entry::class, 'kami_references', 'target_entry_id', 'source_entry_id')
            // One row per Entry, whatever the Fields and Locales linking it; `distinct()` would leave `count()` counting them.
            // @phpstan-ignore argument.type (a column of the pivot, which Larastan looks for on the model)
            ->where('kami_references.id', fn (Builder $first): Builder => $first->from('kami_references', 'first_link')->selectRaw('min(first_link.id)')
                ->whereColumn('first_link.source_entry_id', 'kami_references.source_entry_id')
                ->whereColumn('first_link.target_entry_id', 'kami_references.target_entry_id'));
    }
}
