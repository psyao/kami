<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Psyao\Kami\Facades\Kami;
use Psyao\Kami\Support\SecurityHeaders;

/** The host's own wiring outside the panel, as a site's `bootstrap/app.php` declares it. */
class WorkbenchServiceProvider extends ServiceProvider
{
    public function boot(Router $router): void
    {
        // A site's `$middleware->web(append: [SecurityHeaders::class])`, so its own pages get Kami's policy (ADR 0018).
        $router->pushMiddlewareToGroup('web', SecurityHeaders::class);

        // The site's lang/<locale>/kami-types.php: Testbench's skeleton keeps its own lang directory.
        $this->loadTranslationsFrom(dirname(__DIR__, 2) . '/lang');

        // `composer serve` shows the front, its links at `app.url`, the address it serves. Tests call Kami::routes() themselves and keep the declared URL.
        if (! $this->app->runningUnitTests()) {
            Kami::routes();
            config(['kami.sites.default.url' => config('app.url')]);
        }
    }
}
