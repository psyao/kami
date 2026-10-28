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

        $entryId = Path::query()
            ->where('site', $site->handle)
            ->where('locale', $site->currentLocale())
            ->where('path', Path::normalise($request->path()))
            ->where('status', 200)
            ->value('entry_id');

        // No row, a Translation that is not visible, or an Entry whose type is no longer declared.
        $entry = $entryId === null ? null : Entry::query()->whereKey($entryId)->first();
        $type = $entry?->contentType() ?? abort(404);

        return app()->call($type->render(...), ['entry' => $entry]);
    }
}
