<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Sites\Internal\Site;

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
        $locale = $builder instanceof EntryBuilder ? $builder->locale() : Site::current()->currentLocale();

        $builder->whereHas('translations', fn (Builder $translations) => $translations
            ->whereIn('locale', [$locale, '*'])
            ->where('status', 'published')
            ->where('published_at', '<=', now()));
    }

    public static function isVisible(?Translation $translation): bool
    {
        return $translation?->status === 'published' && $translation->published_at?->lte(now()) === true;
    }
}
