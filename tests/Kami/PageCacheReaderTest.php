<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Facades\Kami;
use Workbench\App\Content\Article;

uses(RefreshDatabase::class);

// The pre-boot reader as public/index.php requires it, run by PHP's built-in web server: the CLI sends no headers.
beforeEach(function () {
    // The Lowest job disables proc_open (tests/infomaniak.ini); the smoke runs the reader under the same settings in FPM.
    if (! function_exists('proc_open')) {
        $this->markTestSkipped('The built-in web server needs proc_open.');
    }

    $this->app->useStoragePath($this->storage = temporaryPath());
    config(['kami.page_cache.enabled' => true]);
    Kami::routes();
    Http::allowStrayRequests();

    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Hello world', 'status' => 'published', 'author' => 'Ada Lovelace']);
    // An admin test earlier in this process leaves Livewire injecting its styles into the response after the page is stored (#434).
    Livewire::flushState();
    $this->stored = $this->get('/articles/hello-world')->assertOk();
    // A Tick in the last five minutes, which that request's visit Tick wrote as well.
    touch("{$this->storage}/framework/kami/tick-cli");

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $this->port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $this->server = Process::env(['KAMI_STORAGE' => $this->storage])
        ->start([PHP_BINARY, '-S', "127.0.0.1:{$this->port}", dirname(__DIR__) . '/Fixtures/page-cache/index.php']);

    for ($tries = 0; ! @fsockopen('127.0.0.1', $this->port) && $tries < 100; $tries++) {
        usleep(20_000);
    }
});

afterEach(function () {
    if (isset($this->server)) {
        $this->server->signal(SIGTERM);
        File::deleteDirectory($this->storage);
    }
});

/** A request to the reader for the Site's host, the port left in `Host` as browsers send it. */
function preBootRequest(array $headers = []): PendingRequest
{
    return Http::baseUrl('http://127.0.0.1:' . test()->port)->withHeaders(['Host' => 'localhost:' . test()->port, ...$headers]);
}

/** The page file the Testbench request wrote, under the manifest's version. */
function storedPage(): string
{
    return glob(test()->storage . '/framework/kami/pages/*/*')[0];
}

function manifestPath(): string
{
    return test()->storage . '/framework/kami/pages/manifest.json';
}

function storedVersion(): string
{
    return json_decode((string) file_get_contents(manifestPath()), true)['version'];
}

it('serves a stored page before Laravel boots, with its headers, its ETag and no-cache', function () {
    $hit = preBootRequest()->get('/articles/hello-world');

    expect($hit->status())->toBe(200)
        ->and($hit->body())->toBe($this->stored->getContent())
        ->and($hit->header('ETag'))->toBe($this->stored->headers->get('ETag'))
        ->and($hit->header('Cache-Control'))->toBe('no-cache, private')
        ->and($hit->header('Content-Type'))->toBe($this->stored->headers->get('Content-Type'))
        ->and($hit->header('Content-Security-Policy'))->toBe($this->stored->headers->get('Content-Security-Policy'));
});

it('answers 304 with no body to an If-None-Match holding the ETag', function (string $ifNoneMatch) {
    $etag = $this->stored->headers->get('ETag');
    $hit = preBootRequest(['If-None-Match' => str_replace('ETAG', $etag, $ifNoneMatch)])->get('/articles/hello-world');

    expect($hit->status())->toBe(304)
        ->and($hit->body())->toBe('')
        ->and($hit->header('ETag'))->toBe($etag);
})->with([
    'the ETag' => ['ETAG'],
    'weak, in a list' => ['"other", W/ETAG'],
    'any' => ['*'],
]);

it('sends the whole page to an If-None-Match of another version', function () {
    $hit = preBootRequest(['If-None-Match' => '"0123456789abcdef-0"'])->get('/articles/hello-world');

    expect($hit->status())->toBe(200)->and($hit->body())->toBe($this->stored->getContent());
});

it('answers HEAD with the headers alone', function () {
    $hit = preBootRequest()->send('HEAD', '/articles/hello-world');

    expect($hit->status())->toBe(200)
        ->and($hit->header('ETag'))->toBe($this->stored->headers->get('ETag'))
        ->and($hit->header('Content-Type'))->toBe($this->stored->headers->get('Content-Type'));
});

it('lets Laravel boot for what Kami never stores', function (Closure $send) {
    expect($send()->body())->toBe('Laravel booted');
})->with([
    'a query string' => [fn () => preBootRequest()->get('/articles/hello-world?page=2')],
    'credentials' => [fn () => preBootRequest(['Authorization' => 'Bearer secret'])->get('/articles/hello-world')],
    'a POST' => [fn () => preBootRequest()->post('/articles/hello-world')],
    'another host' => [fn () => preBootRequest(['Host' => 'blog.localhost'])->get('/articles/hello-world')],
    'a page never stored' => [fn () => preBootRequest()->get('/articles/unknown')],
]);

it('lets Laravel boot once the page is an hour old', function () {
    touch(storedPage(), time() - 3601);

    expect(preBootRequest()->get('/articles/hello-world')->body())->toBe('Laravel booted');

    touch(storedPage(), time() - 3500);

    expect(preBootRequest()->get('/articles/hello-world')->body())->toBe($this->stored->getContent());
});

// With no recent Tick, Laravel answers, and its response starts a visit Tick (ADR 0021).
it('lets Laravel boot when every Tick is over five minutes old or missing', function (Closure $age) {
    foreach (['cli', 'web', 'visit'] as $trigger) {
        $age("{$this->storage}/framework/kami/tick-{$trigger}");
    }

    expect(preBootRequest()->get('/articles/hello-world')->body())->toBe('Laravel booted');
})->with([
    'over five minutes old' => [fn (string $file) => touch($file, time() - 301)],
    'missing' => [fn (string $file) => @unlink($file)],
]);

it('serves the page while any Tick ran in the last five minutes', function (string $trigger) {
    foreach (['cli', 'web', 'visit'] as $old) {
        touch("{$this->storage}/framework/kami/tick-{$old}", time() - 400);
    }
    touch("{$this->storage}/framework/kami/tick-{$trigger}", time() - 240);

    expect(preBootRequest()->get('/articles/hello-world')->body())->toBe($this->stored->getContent());
})->with(['cli', 'web', 'visit']);

it('lets Laravel boot past the next visibility change, or on a missing or malformed manifest (#268)', function (Closure $manifest) {
    $contents = $manifest(storedVersion());
    $contents === null ? unlink(manifestPath()) : file_put_contents(manifestPath(), $contents);

    expect(preBootRequest()->get('/articles/hello-world')->body())->toBe('Laravel booted');
})->with([
    'a passed change' => [fn (string $version) => json_encode(['version' => $version, 'next_change_at' => time()])],
    'missing' => [fn () => null],
    'not JSON' => [fn (string $version) => '{"version":"' . $version],
    'no next change' => [fn (string $version) => json_encode(['version' => $version])],
    'a date string' => [fn (string $version) => json_encode(['version' => $version, 'next_change_at' => '2099-01-01'])],
]);

it('serves the page until the next visibility change', function () {
    file_put_contents(manifestPath(), json_encode(['version' => storedVersion(), 'next_change_at' => time() + 60]));

    expect(preBootRequest()->get('/articles/hello-world')->body())->toBe($this->stored->getContent());
});

it('lets Laravel answer 503 under php artisan down, even with no prerendered page', function () {
    $this->artisan('down')->assertSuccessful();

    expect(preBootRequest()->get('/articles/hello-world')->body())->toBe('Laravel booted');
});
