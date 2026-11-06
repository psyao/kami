<?php

declare(strict_types=1);

namespace Psyao\Kami;

use Illuminate\Support\Facades\Route;
use Psyao\Kami\Media\Internal\PublicMedia;
use Psyao\Kami\Media\Internal\RenderConversion;
use Psyao\Kami\Routing\Internal\RenderEntry;
use Psyao\Kami\Routing\Internal\RenderPreviewLink;
use Psyao\Kami\Support\Internal\FrontSecurityHeaders;

/** What a site calls on Kami through the `Kami` facade. */
final class Kami
{
    /**
     * Registers Kami's front: a fallback route, `/kami/share/{token}` for Preview links and `/media/{ulid}/{v}/{file}` for Conversions and the files of documents and SVG.
     *
     * The fallback lets the site's own routes always win. All run without the `web` group's session, cookies and CSRF, even when called inside it; the fallback in the `kami` middleware group.
     */
    public function routes(): void
    {
        // Sets its Locale itself, as its URL names none, and sends no referrer: the token must not leave through an outbound link.
        Route::get('kami/share/{token}', RenderPreviewLink::class)
            ->middleware(FrontSecurityHeaders::class . ':Referrer-Policy=no-referrer')
            ->withoutMiddleware('web');
        // An SVG's own file first, under a CSP of its own, enforced even when the front's is report-only: the front's middleware replaces any the response sets.
        Route::get('media/{media}/{version}/{svg}', RenderConversion::class)
            ->where('svg', '[a-z0-9-]+\.svg')
            ->middleware(FrontSecurityHeaders::class . ':Content-Security-Policy=' . PublicMedia::SVG_HEADERS['Content-Security-Policy'])
            ->withoutMiddleware('web');
        Route::get('media/{media}/{version}/{file}', RenderConversion::class)->middleware(FrontSecurityHeaders::class)->withoutMiddleware('web');
        Route::fallback(RenderEntry::class)->middleware('kami')->withoutMiddleware('web');
    }
}
