<?php

declare(strict_types=1);

namespace Psyao\Kami\Routing\Internal;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use LogicException;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Content\Taxonomy;
use Psyao\Kami\Seo\Internal\ResolvePageMeta;
use Psyao\Kami\Sites\Internal\Site;

/**
 * Kami's fallback route: one indexed read of `kami_paths` at the request path and its prefixes, then the visible Entry through its type's `render()` (ADR 0008, ADR 0009).
 * The prefixes hold a nested Entry's ancestors, which must be visible too (ADR 0019).
 *
 * @internal
 */
final class RenderEntry
{
    public function __invoke(Request $request): mixed
    {
        $site = Site::current();
        $locale = $site->currentLocale();
        // `ResolveLocale` found the Locale in the first segment, which no stored Path holds.
        $path = $locale === $site->defaultLocale() ? $request->path() : substr($request->path(), strlen($locale) + 1);

        $path = Path::normalise($path);
        $archive = Path::archiveAt($path);

        // Before any query: an Archive hides a stored Path at its Prefix, which `SaveEntry` refuses and `kami:doctor` reports.
        if ($archive instanceof ContentType) {
            return $this->renderArchive($request, $archive, $site);
        }

        // MySQL's and MariaDB's binary collation ignores trailing spaces, which no stored Path has.
        // No stored Path is longer, and each segment of a longer one would be one more bound prefix.
        $rows = str_ends_with($path, ' ') || mb_strlen($path) > Path::MAX_LENGTH ? new Collection : VisiblePaths::at($site->handle, [$locale], VisiblePaths::prefixes($path));
        $row = $rows->first(fn (object $row): bool => $row->path === $path);
        $status = $row === null ? null : (int) $row->status;

        abort_if($status === 410, 410);

        if ($status === 301) {
            // At the replacement's current URL, so Redirects never chain; one not visible here answers as absent, its address unrevealed.
            $url = Entry::query()->whereKey($row->entry_id)->first()?->url();

            return $url === null ? abort(404) : redirect()->away($url, 301);
        }

        $entryId = $status === 200 ? $row->entry_id : null;

        // No row, a Translation that is not visible, an Entry whose type is no longer declared, or one flipped to no URL whose rows wait for `SyncPaths`.
        $entry = $entryId === null ? null : Entry::query()->whereKey($entryId)->first();
        $type = $entry?->contentType();
        abort_unless($entry instanceof Entry && $type?->isRoutable() === true, 404);
        // Under an ancestor that is not visible here.
        abort_unless(! $type->isHierarchical() || VisiblePaths::reachable(VisiblePaths::index($rows, $locale), $entry->parent_id), 404);

        return self::render($request, $entry);
    }

    /** A visible Entry of a routable type through its type's `render()`, also re-rendered by the form route with a Form's errors. */
    public static function render(Request $request, Entry $entry, ViewErrorBag $errors = new ViewErrorBag): mixed
    {
        $type = $entry->contentType() ?? throw new LogicException("The Entry [{$entry->id}] has no declared type.");
        // The page's Entry, for what the layout renders around it: `<x-kami::locales />`, `<x-kami::form>`.
        $request->attributes->set('kami.entry', $entry);
        // Before `render()`, so it can pass it on, as an Inertia page does.
        $meta = ResolvePageMeta::forEntry($type, $entry);
        $cursor = $type instanceof Taxonomy ? $request->query(Listing::CURSOR) : null;
        $request->attributes->set(ResolvePageMeta::ATTRIBUTE, $cursor === null ? $meta : ResolvePageMeta::paged($meta));
        // `$errors` and `@error` work in a site's form markup, as in the `web` group, whose own bag is kept.
        if (! $request->hasSession() || $errors->any()) {
            View::share('errors', $errors);
        }

        return self::call($type, $entry, $cursor);
    }

    /**
     * Calls the type's `render()`; a Taxonomy's with its Term Archive: the visible Entries of routable types linking the Term, newest first.
     * A Preview passes no cursor, so its first page.
     */
    public static function call(ContentType $type, Entry $entry, mixed $cursor = null): mixed
    {
        if (! $type instanceof Taxonomy) {
            return app()->call($type->render(...), ['entry' => $entry]);
        }

        $site = Site::current();
        $routable = array_values(array_map(fn (ContentType $type): string => $type->handle(), array_filter(resolve(ContentTypes::class)->all(), fn (ContentType $type): bool => $type->isRoutable())));
        $locale = $entry->readLocale();
        $query = Entry::query()->readIn($locale)->where('site', $site->handle)->whereIn('type', $routable)
            // A link in the Locale read, or a Shared one; a Term never saved has no id, so no link. An `in` read from the target's index:
            // a correlated `exists` made SQLite walk every published Translation by date, 1.8 s at 30,000 Entries.
            ->whereIn('kami_entries.id', fn (Builder $links): Builder => $links->select('source_entry_id')->from('kami_references')
                ->where('target_entry_id', $entry->getRawOriginal('id'))
                ->where(fn (Builder $inLocale): Builder => $inLocale->where('locale', $locale)->orWhereNull('locale')));
        $entries = Listing::page($query, false, $type->perPage(), $entry->url($locale) ?? '', $cursor);

        return app()->call($type->render(...), ['entry' => $entry, 'term' => $entry, 'entries' => $entries]);
    }

    /** An Archive's page in the current Locale, at its type's Prefix. */
    private function renderArchive(Request $request, ContentType $type, Site $site): mixed
    {
        $locale = $site->currentLocale();
        $cursor = $request->query(Listing::CURSOR);
        $query = Entry::query()->readIn($locale)->where('site', $site->handle)->where('type', $type->handle());
        $entries = Listing::page($query, $type->isOrderable(), $type->perPage(), $site->url($locale, Path::normalise($type->prefix())), $cursor);
        $request->attributes->set(ResolvePageMeta::ATTRIBUTE, ResolvePageMeta::forArchive($type, $cursor !== null));

        return app()->call($type->renderArchive(...), ['entries' => $entries]);
    }
}
