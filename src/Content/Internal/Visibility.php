<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use LogicException;
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
     * When a Translation of the Site next becomes visible, as last written: no query, for M7's page cache (ADR 0019). Null when nothing is Scheduled or nothing was written yet; a moment already past stays until the next write.
     */
    public static function nextChangeAt(Site $site): ?CarbonImmutable
    {
        $path = self::nextChangePath($site);
        $next = File::exists($path) ? File::json($path)['next_change_at'] ?? null : null;

        return is_string($next) ? CarbonImmutable::parse($next) : null;
    }

    /**
     * Recomputes the Site's next visibility change, in one query on the `(status, published_at)` index, and replaces its file atomically. Called after commit by the core actions and `kami:install`; a failure is reported, never thrown, as the write it follows has committed.
     */
    public static function writeNextChange(Site $site): void
    {
        rescue(function () use ($site): void {
            $next = DB::table('kami_translations')
                ->where('status', Translation::PUBLISHED)
                ->where('published_at', '>', now())
                ->whereIn('entry_id', DB::table('kami_entries')->select('id')->where('site', $site->handle))
                ->min('published_at');
            $path = self::nextChangePath($site);

            if (! File::isDirectory(dirname($path))) {
                File::ensureDirectoryExists(dirname($path));
                // Describes this machine's content: kept out of git, as Laravel does cache/, sessions/ and views/.
                File::put(dirname($path) . '/.gitignore', "*\n!.gitignore\n");
            }

            File::replace($path, json_encode(['next_change_at' => is_string($next) ? CarbonImmutable::parse($next, 'UTC')->toIso8601String() : null], JSON_THROW_ON_ERROR));
        });
    }

    private static function nextChangePath(Site $site): string
    {
        return storage_path("framework/kami/visibility-{$site->handle}.json");
    }
}
