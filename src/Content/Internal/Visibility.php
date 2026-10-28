<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Sites\Internal\Site;

/**
 * What "visible" means, in one place: a published Translation in the current Locale whose `published_at` has come, at read time (ADR 0012, ADR 0019).
 *
 * @internal
 *
 * @implements Scope<Entry>
 */
final class Visibility implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereHas('translations', fn (Builder $translations) => $translations
            ->where('locale', Site::current()->currentLocale())
            ->where('status', 'published')
            ->where('published_at', '<=', now()));
    }
}
