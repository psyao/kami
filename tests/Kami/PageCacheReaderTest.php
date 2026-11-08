<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Facades\Kami;
use Workbench\App\Content\Article;

uses(RefreshDatabase::class);

// The pre-boot reader as public/index.php requires it, run by PHP's built-in web server: the CLI sends no headers.
beforeEach(function () {
    $this->app->useStoragePath($this->storage = temporaryPath());
    config(['kami.page_cache.enabled' => true]);
    Kami::routes();
    Http::allowStrayRequests();

    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Hello world', 'status' => 'published', 'author' => 'Ada Lovelace']);
    $this->stored = $this->get('/articles/hello-world')->assertOk();

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
    $this->server->signal(SIGTERM);
    File::deleteDirectory($this->storage);
});

/** A request to the reader for the Site's host, the port left in `Host` as browsers send it. */
function reader(array $headers = []): PendingRequest
{
    return Http::baseUrl('http://127.0.0.1:' . test()->port)->withHeaders(['Host' => 'localhost:' . test()->port, ...$headers]);
}

function bootedLaravel(Response $response): bool
{
    return $response->body() === 'Laravel booted';
}

it('serves a stored page before Laravel boots, with its headers, its ETag and no-cache', function () {
    $hit = reader()->get('/articles/hello-world');

    expect($hit->status())->toBe(200)
        ->and($hit->body())->toBe($this->stored->getContent())
        ->and($hit->header('ETag'))->toBe($this->stored->headers->get('ETag'))
        ->and($hit->header('Cache-Control'))->toBe('no-cache, private')
        ->and($hit->header('Content-Type'))->toBe($this->stored->headers->get('Content-Type'))
        ->and($hit->header('Content-Security-Policy'))->toBe($this->stored->headers->get('Content-Security-Policy'));
});

it('answers 304 with no body to an If-None-Match holding the ETag', function (string $ifNoneMatch) {
    $etag = $this->stored->headers->get('ETag');
    $hit = reader(['If-None-Match' => str_replace('ETAG', $etag, $ifNoneMatch)])->get('/articles/hello-world');

    expect($hit->status())->toBe(304)
        ->and($hit->body())->toBe('')
        ->and($hit->header('ETag'))->toBe($etag);
})->with([
    'the ETag' => ['ETAG'],
    'weak, in a list' => ['"other", W/ETAG'],
    'any' => ['*'],
]);

it('sends the whole page to an If-None-Match of another version', function () {
    $hit = reader(['If-None-Match' => '"0123456789abcdef-0"'])->get('/articles/hello-world');

    expect($hit->status())->toBe(200)->and($hit->body())->toBe($this->stored->getContent());
});

it('answers HEAD with the headers alone', function () {
    $hit = reader()->send('HEAD', '/articles/hello-world');

    expect($hit->status())->toBe(200)
        ->and($hit->header('ETag'))->toBe($this->stored->headers->get('ETag'))
        ->and($hit->header('Content-Type'))->toBe($this->stored->headers->get('Content-Type'));
});

it('lets Laravel boot for what Kami never stores', function (Closure $send) {
    expect(bootedLaravel($send()))->toBeTrue();
})->with([
    'a query string' => [fn () => reader()->get('/articles/hello-world?page=2')],
    'credentials' => [fn () => reader(['Authorization' => 'Bearer secret'])->get('/articles/hello-world')],
    'a POST' => [fn () => reader()->post('/articles/hello-world')],
    'another host' => [fn () => reader(['Host' => 'blog.localhost'])->get('/articles/hello-world')],
    'a page never stored' => [fn () => reader()->get('/articles/unknown')],
]);

/** The page file the Testbench request wrote, under the manifest's version. */
function storedPage(): string
{
    return glob(test()->storage . '/framework/kami/pages/*/*')[0];
}

it('lets Laravel boot once the page is an hour old', function () {
    touch(storedPage(), time() - 3601);

    expect(bootedLaravel(reader()->get('/articles/hello-world')))->toBeTrue();

    touch(storedPage(), time() - 3500);

    expect(bootedLaravel(reader()->get('/articles/hello-world')))->toBeFalse();
});

it('lets Laravel boot past the next visibility change, or on a missing or malformed manifest (#268)', function (Closure $manifest) {
    $path = $this->storage . '/framework/kami/pages/manifest.json';
    $version = json_decode((string) file_get_contents($path), true)['version'];
    $contents = $manifest($version);
    $contents === null ? unlink($path) : file_put_contents($path, $contents);

    expect(bootedLaravel(reader()->get('/articles/hello-world')))->toBeTrue();
})->with([
    'a passed change' => [fn (string $version) => json_encode(['version' => $version, 'next_change_at' => time()])],
    'missing' => [fn () => null],
    'not JSON' => [fn (string $version) => '{"version":"' . $version],
    'no next change' => [fn (string $version) => json_encode(['version' => $version])],
    'a date string' => [fn (string $version) => json_encode(['version' => $version, 'next_change_at' => '2099-01-01'])],
]);

it('serves the page until the next visibility change', function () {
    $path = $this->storage . '/framework/kami/pages/manifest.json';
    $version = json_decode((string) file_get_contents($path), true)['version'];
    file_put_contents($path, json_encode(['version' => $version, 'next_change_at' => time() + 60]));

    expect(bootedLaravel(reader()->get('/articles/hello-world')))->toBeFalse();
});
