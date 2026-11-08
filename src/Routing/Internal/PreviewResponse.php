<?php

declare(strict_types=1);

namespace Psyao\Kami\Routing\Internal;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Sites\Internal\Site;
use Symfony\Component\HttpFoundation\Response;

/**
 * A page visitors may not see, the admin Preview's or a Preview link's, rendered through its type in its Locale, kept out of search engines and caches (ADR 0019, ADR 0027).
 *
 * @internal
 */
final class PreviewResponse
{
    /** @param  string|null  $banner  What `<x-kami::preview-banner />` says in place of the Preview's own text. */
    public static function make(Request $request, ContentType $type, Entry $entry, ?string $banner = null): Response
    {
        // As `ResolveLocale` does from the URL, which names no Locale here.
        Site::useLocale($entry->readLocale());
        app()->setLocale($entry->readLocale());
        $request->attributes->set('kami.preview', true);
        $request->attributes->set('kami.preview.banner', $banner);
        $request->attributes->set('kami.entry', $entry);

        $response = Route::toResponse($request, app()->call($type->render(...), ['entry' => $entry]));
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
