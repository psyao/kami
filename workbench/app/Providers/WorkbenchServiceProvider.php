<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Psyao\Kami\Support\SecurityHeaders;

/** The host's own wiring outside the panel, as a site's `bootstrap/app.php` declares it. */
class WorkbenchServiceProvider extends ServiceProvider
{
    public function boot(Router $router): void
    {
        // A site's `$middleware->web(append: [SecurityHeaders::class])`, so its own pages get Kami's policy (ADR 0018).
        $router->pushMiddlewareToGroup('web', SecurityHeaders::class);
    }
}
