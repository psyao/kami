<?php

declare(strict_types=1);

namespace Psyao\Kami;

use Illuminate\Support\Facades\Route;
use Psyao\Kami\Routing\Internal\RenderEntry;

/** What a site calls on Kami through the `Kami` facade. */
final class Kami
{
    /**
     * Registers Kami's front: one fallback route, so the site's own routes always win.
     *
     * It runs in the `kami` middleware group, without the `web` group's session, cookies and CSRF, even when called inside it.
     */
    public function routes(): void
    {
        // ponytail: an empty group until ResolveLocale (#97) and SecurityHeaders (#102) join it.
        Route::middlewareGroup('kami', []);

        Route::fallback(RenderEntry::class)->middleware('kami')->withoutMiddleware('web');
    }
}
