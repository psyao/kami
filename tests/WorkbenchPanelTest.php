<?php

use Filament\Facades\Filament;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Schema;

it('adds Kami to the host panel', function () {
    expect(Filament::getPanel('admin')->hasPlugin('kami'))->toBeTrue();

    $this->get('/admin/login')
        ->assertOk()
        ->assertSee('/css/psyao/kami/kami.css', escape: false);
});

it('registers no route or middleware of its own', function () {
    $kamiSource = dirname(__DIR__) . '/src/';
    $definedByKami = function (Route $route) use ($kamiSource): bool {
        $uses = $route->getAction('uses');
        $file = match (true) {
            $uses instanceof Closure => (new ReflectionFunction($uses))->getFileName(),
            $route->getControllerClass() !== null => (new ReflectionClass($route->getControllerClass()))->getFileName(),
            default => false, // a view or redirect route: no PHP of its own
        };

        return str_starts_with((string) $file, $kamiSource);
    };
    $router = app('router');

    // The panel's routes are the host's: KamiPlugin only adds pages to them.
    $routes = collect($router->getRoutes()->getRoutes())->reject(fn (Route $route) => str_starts_with((string) $route->getName(), 'filament.'));
    $middleware = collect([...app(Kernel::class)->getGlobalMiddleware(), ...$router->getMiddleware(), ...collect($router->getMiddlewareGroups())->flatten()]);

    expect($routes->filter($definedByKami))->toBeEmpty()
        ->and($middleware->filter(fn (mixed $class) => is_string($class) && str_starts_with($class, 'Psyao\\Kami\\')))->toBeEmpty();
});

it('runs in UTC whatever the host time zone', function () {
    expect(date_default_timezone_get())->toBe('UTC')
        ->and(config('app.timezone'))->toBe('UTC')
        ->and(now()->timezoneName)->toBe('UTC');
});

it('creates no kami_ table yet', function () {
    $this->artisan('migrate')->assertSuccessful();

    $tables = collect(Schema::getTableListing(schemaQualified: false));

    expect($tables)->not->toBeEmpty()
        ->and($tables->filter(fn (string $table) => str_starts_with($table, 'kami_')))->toBeEmpty();
});
