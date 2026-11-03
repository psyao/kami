<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal;

use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Psyao\Kami\Content\Internal\EntrySnapshot;
use Psyao\Kami\Sites\Internal\Site;
use Symfony\Component\HttpFoundation\Response;

/**
 * The page an editor's unsaved form makes, through its type's `render()`, for that User only (ADR 0019): another one gets a 404.
 *
 * @internal
 *
 * @phpstan-import-type Snapshot from EntrySnapshot
 */
final class RenderPreview
{
    /** Held one hour, under a random token per page, never in the session. */
    public const string CACHE_KEY = 'kami.preview.';

    public function __invoke(Request $request, string $token): Response
    {
        /** @var array{user: int|string, snapshot: Snapshot}|null $preview */
        $preview = Cache::get(self::CACHE_KEY . $token);
        abort_unless(is_array($preview) && $preview['user'] === Filament::auth()->id(), 404);

        $entry = EntrySnapshot::rebuild($preview['snapshot']);
        $type = $entry->contentType();
        abort_unless($type?->isRoutable() === true && Gate::forUser(Filament::auth()->user())->allows("kami.entries.{$type->handle()}.view"), 404);

        // As `ResolveLocale` does from the URL, which names no Locale here.
        Site::useLocale($entry->readLocale());
        app()->setLocale($entry->readLocale());
        $request->attributes->set('kami.preview', true);

        $response = Route::toResponse($request, app()->call($type->render(...), ['entry' => $entry]));
        $response->headers->set('X-Robots-Tag', 'noindex');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
