<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Psyao\Kami\Facades\Kami;
use Psyao\Kami\Support\SecurityHeaders;

use function Orchestra\Testbench\workbench_path;

uses(RefreshDatabase::class);

// Workbench's error views stand for the starter kit's: they print the Locale, the 404 through `x-kami::seo`, the 5xx with no database and no build.
beforeEach(function () {
    // Testbench's application locale is `en`: the second Locale, `fr`, is the one only Kami can set.
    config(['app.debug' => false, 'kami.sites.default.locales' => ['en', 'fr' => ['region' => 'CH']]]);
    // Ahead of Testbench's skeleton, which has a 503 of its own.
    config(['view.paths' => [workbench_path('resources/views'), ...config('view.paths')]]);
    Kami::exceptions(new Exceptions(resolve(ExceptionHandler::class)));
});

afterEach(function () {
    if (isset($this->storage)) {
        File::deleteDirectory($this->storage);
    }
});

it('renders a 404 of the host\'s own routes in the Locale its first segment names, with the Site\'s head', function () {
    Route::get('fr/membres', fn () => abort(404));

    $this->get('/fr/membres')->assertNotFound()
        ->assertSee('<html lang="fr">', escape: false)
        ->assertSee('<meta property="og:locale" content="fr_CH">', escape: false)
        ->assertSee('<title>Not Found – Laravel</title>', escape: false);
});

it('renders a 404 no route answers in the Locale its first segment names, the default one without', function () {
    $this->get('/fr/nowhere')->assertNotFound()->assertSee('<html lang="fr">', escape: false);
    $this->get('/nowhere')->assertNotFound()->assertSee('<html lang="en">', escape: false);
});

it('renders a 404 of Kami\'s routes in the Locale its first segment names', function () {
    Kami::routes();

    $this->get('/fr/articles/missing')->assertNotFound()->assertSee('<html lang="fr">', escape: false);
});

it('renders a 500 in the Locale with no build, under the CSP of the route that failed', function () {
    // The starter kit appends `SecurityHeaders` to the `web` group: the route's middleware receives the rendered error.
    Route::get('fr/boom', fn () => throw new RuntimeException('Boom'))->middleware(SecurityHeaders::class);
    Route::get('fr/fine', fn () => 'Fine')->middleware(SecurityHeaders::class);

    expect(public_path('build/manifest.json'))->not->toBeFile();
    $this->get('/fr/boom')->assertInternalServerError()
        ->assertSee('<html lang="fr">', escape: false)
        ->assertDontSee('Boom')
        ->assertHeader('Content-Security-Policy', $this->get('/fr/fine')->headers->get('Content-Security-Policy'));
});

it('renders the 503 of maintenance mode in the Locale', function () {
    // A `storage/` of its own: parallel processes share the skeleton's, and `down` would stop them all.
    $this->app->useStoragePath($this->storage = temporaryPath());
    File::ensureDirectoryExists($this->storage . '/framework');
    $this->artisan('down')->assertSuccessful();

    $this->get('/fr/articles/bonjour')->assertServiceUnavailable()->assertSee('<html lang="fr">', escape: false);
});
