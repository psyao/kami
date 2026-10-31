<?php

declare(strict_types=1);

namespace Psyao\Kami\Routing\Internal;

use Illuminate\Http\Request;
use Psyao\Kami\Content\Entry;
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
        $entryId = str_ends_with($path, ' ') ? null : Path::query()
            ->where('site', $site->handle)
            ->where('locale', $locale)
            ->where('path', $path)
            ->where('status', 200)
            ->value('entry_id');

        // No row, a Translation that is not visible, an Entry whose type is no longer declared, or one flipped to no URL whose rows wait for `SyncPaths`.
        $entry = $entryId === null ? null : Entry::query()->whereKey($entryId)->first();
        $type = $entry?->contentType() ?? abort(404);
        abort_unless($type->isRoutable(), 404);

        return app()->call($type->render(...), ['entry' => $entry]);
    }
}
