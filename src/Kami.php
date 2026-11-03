<?php

declare(strict_types=1);

namespace Psyao\Kami;

use Illuminate\Support\Facades\Route;
use Psyao\Kami\Routing\Internal\RenderEntry;
use Psyao\Kami\Routing\Internal\RenderPreviewLink;
use Psyao\Kami\Support\Internal\FrontSecurityHeaders;

/** What a site calls on Kami through the `Kami` facade. */
final class Kami
{
    /**
     * Registers Kami's front: one fallback route, so the site's own routes always win, and `/kami/share/{token}` for Preview links.
     *
     * Both run without the `web` group's session, cookies and CSRF, even when called inside it; the fallback in the `kami` middleware group.
     */
    public function routes(): void
    {
        // Sets its Locale itself, as its URL names none, and sends no referrer: the token must not leave through an outbound link.
        Route::get('kami/share/{token}', RenderPreviewLink::class)
            ->middleware(FrontSecurityHeaders::class . ':Referrer-Policy=no-referrer')
            ->withoutMiddleware('web');
        Route::fallback(RenderEntry::class)->middleware('kami')->withoutMiddleware('web');
    }
}
