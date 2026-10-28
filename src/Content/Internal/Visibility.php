<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use LogicException;
use Psyao\Kami\Content\Entry;

/**
 * What "visible" means, in one place: a published Translation in the Locale read, or the `'*'` one, whose `published_at` has come, at read time (ADR 0012, ADR 0019).
 *
 * @internal
 *
 * @implements Scope<Entry>
 */
final class Visibility implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        // Always an EntryBuilder (`UseEloquentBuilder` on Entry), which the Scope contract does not name.
        if (! $builder instanceof EntryBuilder) {
            throw new LogicException('Visibility applies to Entry queries only.');
        }

        $builder->whereHas('translations', fn (Builder $translations) => $translations
            ->whereIn('locale', [$builder->locale(), '*'])
            ->where('status', 'published')
            ->where('published_at', '<=', now()));
    }

    public static function isVisible(?Translation $translation): bool
    {
        return $translation?->status === 'published' && $translation->published_at?->lte(now()) === true;
    }
}
