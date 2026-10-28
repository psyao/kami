<?php

declare(strict_types=1);

namespace Psyao\Kami;

use Illuminate\Support\Facades\Route;
use Psyao\Kami\Routing\Internal\RenderEntry;
use Psyao\Kami\Sites\Internal\ResolveLocale;

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
        // ponytail: SecurityHeaders (#102) joins the group.
        Route::middlewareGroup('kami', [ResolveLocale::class]);

        Route::fallback(RenderEntry::class)->middleware('kami')->withoutMiddleware('web');
    }
}
