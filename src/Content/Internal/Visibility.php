<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\DB;
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
            ->where('status', Translation::PUBLISHED)
            ->where('published_at', '<=', now()));
    }

    public static function isVisible(?Translation $translation): bool
    {
        return $translation?->status === Translation::PUBLISHED && $translation->published_at?->lte(now()) === true;
    }

    /**
     * When a Translation of any Site next becomes visible, out of the Trash, in one query on the `(status, published_at)` index; null when nothing is Scheduled.
     */
    public static function nextChange(): ?CarbonImmutable
    {
        $next = DB::table('kami_translations')
            ->where('status', Translation::PUBLISHED)
            ->where('published_at', '>', now())
            ->whereIn('entry_id', DB::table('kami_entries')->select('id')->whereNull('deleted_at'))
            ->min('published_at');

        return is_string($next) ? CarbonImmutable::parse($next, 'UTC') : null;
    }
}
