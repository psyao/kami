<?php

declare(strict_types=1);

namespace Psyao\Kami\Routing\Internal;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use LogicException;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Seo\Internal\ResolvePageMeta;
use Psyao\Kami\Sites\Internal\Site;

/**
 * Kami's fallback route: one indexed read of `kami_paths`, then the visible Entry through its type's `render()` (ADR 0008, ADR 0009).
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

        // MySQL's and MariaDB's binary collation ignores trailing spaces, which no stored Path has.
        $row = str_ends_with($path, ' ') ? null : Path::query()
            ->where('site', $site->handle)
            ->where('locale', $locale)
            ->where('path', $path)
            ->first(['status', 'entry_id']);

        abort_if($row?->status === 410, 410);

        if ($row?->status === 301) {
            // At the replacement's current URL, so Redirects never chain; one not visible here answers as absent, its address unrevealed.
            $url = Entry::query()->whereKey($row->entry_id)->first()?->url();

            return $url === null ? abort(404) : redirect()->away($url, 301);
        }

        $entryId = $row?->status === 200 ? $row->entry_id : null;

        // No row, a Translation that is not visible, an Entry whose type is no longer declared, or one flipped to no URL whose rows wait for `SyncPaths`.
        $entry = $entryId === null ? null : Entry::query()->whereKey($entryId)->first();
        abort_unless($entry instanceof Entry && $entry->contentType()?->isRoutable() === true, 404);

        return self::render($request, $entry);
    }

    /** A visible Entry of a routable type through its type's `render()`, also re-rendered by the form route with a Form's errors. */
    public static function render(Request $request, Entry $entry, ViewErrorBag $errors = new ViewErrorBag): mixed
    {
        $type = $entry->contentType() ?? throw new LogicException("The Entry [{$entry->id}] has no declared type.");
        // The page's Entry, for what the layout renders around it: `<x-kami::locales />`, `<x-kami::form>`.
        $request->attributes->set('kami.entry', $entry);
        // Before `render()`, so it can pass it on, as an Inertia page does.
        $request->attributes->set(ResolvePageMeta::ATTRIBUTE, ResolvePageMeta::forEntry($type, $entry));
        // `$errors` and `@error` work in a site's form markup, as in the `web` group, whose own bag is kept.
        if (! $request->hasSession() || $errors->any()) {
            View::share('errors', $errors);
        }

        return app()->call($type->render(...), ['entry' => $entry]);
    }
}
