<?php

declare(strict_types=1);

use Dom\Element;
use Dom\HTMLDocument;
use Filament\Facades\Filament;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Auth\User;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Schema;
use Psyao\Kami\Facades\Kami;

it('adds Kami to the host panel', function () {
    expect(Filament::getPanel('admin')->hasPlugin('kami'))->toBeTrue();

    $this->get('/admin/login')
        ->assertOk()
        ->assertSee('/css/psyao/kami/kami.css', escape: false);
});

it('draws the User avatar inline, with no remote URL in the panel markup', function () {
    // Filament opens its panel to a User that is not a `FilamentUser` in the local environment only.
    config(['app.env' => 'local']);
    $this->artisan('migrate')->assertSuccessful();
    $user = User::forceCreate(['name' => 'Ada Lovelace', 'email' => 'ada@example.test', 'password' => 'secret']);

    $page = HTMLDocument::createFromString((string) $this->actingAs($user)->get('/admin')->assertOk()->getContent(), LIBXML_NOERROR);

    // The markup only, where the avatar `<img>` sits: a URL built later by JavaScript is not seen here.
    $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
    $remote = collect($page->querySelectorAll('[src], [href]'))
        ->map(fn (Element $element) => $element->getAttribute('src') ?? $element->getAttribute('href'))
        ->filter(fn (string $url) => preg_match('#^(?:[a-z]+:)?//#i', $url) === 1 && parse_url($url, PHP_URL_HOST) !== $appHost);

    expect($remote)->toBeEmpty()
        ->and($page->querySelector('img.fi-user-avatar')?->getAttribute('src'))->toStartWith('data:image/svg+xml;base64,');
});

it('registers no route or middleware until the host calls Kami::routes()', function () {
    $kamiSource = dirname(__DIR__, 2) . '/src/';
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

    Kami::routes();

    expect(collect($router->getRoutes()->getRoutes())->filter($definedByKami)->map->uri()->values()->all())->toBe(['{fallbackPlaceholder}']);
});

it('runs in UTC whatever the host time zone', function () {
    expect(date_default_timezone_get())->toBe('UTC')
        ->and(config('app.timezone'))->toBe('UTC')
        ->and(now()->timezoneName)->toBe('UTC');
});

it('creates the package tables', function () {
    $this->artisan('migrate')->assertSuccessful();

    expect(Schema::hasTable('kami_entries'))->toBeTrue()
        ->and(Schema::hasTable('kami_translations'))->toBeTrue()
        ->and(Schema::hasTable('kami_paths'))->toBeTrue();
});
