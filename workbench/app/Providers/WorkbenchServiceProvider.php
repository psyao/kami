<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Psyao\Kami\Facades\Kami;
use Psyao\Kami\Media\ImagePreset;
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

        // The Workbench's views first in `view.paths`, which the error pages' namespace and make:view read: Testbench appends them
        // after its skeleton's, whose errors/503 would hide the Workbench's (#508). The view finder keeps Testbench's order.
        $views = dirname(__DIR__, 2) . '/resources/views';
        $this->app->booted(fn () => config(['view.paths' => collect(config()->array('view.paths'))->prepend($views)->unique()->values()->all()]));

        // A site's `media.presets` in config/kami.php: Testbench's workbench/config would replace the whole `media` key.
        config(['kami.media.presets' => [ImagePreset::make('card')->ratio(4, 3)->widths([320, 640, 960, 1280])->sizes('(min-width: 60rem) 50vw, 100vw')]]);

        // A site's stylesheet, served from 'self' as the front policy requires; declared before Kami's routes, which would take its path.
        $router->get('workbench.css', fn () => response()->file(dirname(__DIR__, 2) . '/resources/css/front.css', ['Content-Type' => 'text/css']));
        // The Forms guide's script, which submits the contact Form without a reload (docs/guide/forms.md).
        $router->get('workbench-form.js', fn () => response()->file(dirname(__DIR__, 2) . '/resources/js/form.js', ['Content-Type' => 'text/javascript']));

        // `composer serve` shows the front, its links at `app.url`, the address it serves. Tests call Kami::routes() themselves and keep the declared URL.
        if (! $this->app->runningUnitTests()) {
            Kami::routes();
            config(['kami.sites.default.url' => config('app.url')]);
        }
    }
}
