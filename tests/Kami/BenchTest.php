<?php

declare(strict_types=1);

use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Psyao\Kami\Routing\Internal\BenchCommand;

beforeEach(function () {
    $this->app->useStoragePath(temporaryPath());
    Http::preventStrayRequests();
});

/**
 * Answers 200 with these server times, in ms, by whether the request carries `?kami-bench=`, as cURL's transfer statistics over plain HTTP:
 * DNS and connect before `pretransfer_time`, no TLS, so `appconnect_time` is 0.
 * Without `?kami-bench=`, the page is cold, as after a deploy: the first request is a 500 ms render with no ETag, the page cache storing it once sent;
 * the next `$hits` responses carry the page cache's ETag, as the page cache serves them.
 * With a user, a request not signed in as them gets a Digest challenge first, as a staging password answers.
 *
 * @param  list<float>  $cached
 * @param  list<float>  $uncached
 */
function serving(array $cached, array $uncached, ?string $user = null, int $hits = 10): void
{
    // The fake's own response carries no statistics once it is a promise, so the header is added on the way out.
    // Requests the fake has answered without `?kami-bench=`: the first is the cold render.
    $served = 0;
    Http::globalMiddleware(function (callable $handler) use (&$hits, &$served) {
        return function ($request, array $options) use ($handler, &$hits, &$served) {
            return $handler($request, $options)->then(function ($response) use ($request, &$hits, &$served) {
                return ! str_contains((string) $request->getUri(), 'kami-bench=') && $response->getStatusCode() === 200 && $served > 1 && $hits-- > 0
                    ? $response->withHeader('ETag', '"0123456789abcdef-' . str_repeat('a', 32) . '"')
                    : $response;
            });
        };
    });

    Http::fake(function (Request $request, array $options) use (&$cached, &$uncached, $user, &$served) {
        // Guzzle 8 answers the challenge in its auth middleware; Guzzle 7 leaves Digest to cURL, as options.
        $signedIn = str_starts_with($request->header('Authorization')[0] ?? '', "Digest username=\"{$user}\"")
            || ($options['curl'][CURLOPT_HTTPAUTH] ?? null) === CURLAUTH_DIGEST && str_starts_with($options['curl'][CURLOPT_USERPWD] ?? '', "{$user}:");

        if ($user !== null && ! $signedIn) {
            return Http::response('', 401, ['WWW-Authenticate' => 'Digest realm="staging", nonce="0123456789abcdef", qop="auth"']);
        }
        $server = match (true) {
            str_contains($request->url(), 'kami-bench=') => array_shift($uncached),
            ++$served === 1 => 500.0,
            default => array_shift($cached),
        };
        $options['on_stats'](new TransferStats($request->toPsrRequest(), null, null, null, ['appconnect_time' => 0.0, 'pretransfer_time' => 0.04, 'starttransfer_time' => 0.04 + $server / 1000]));

        // An array, not a promise: the fake would call `on_stats` again, with no statistics.
        return ['served' => true];
    });
}

it('prints the median server time of a cached and an uncached page, and keeps it for the doctor', function () {
    serving([95, 9, 8, 12, 7, 10, 11, 9, 8, 13], [140, 150, 160, 145, 155, 150, 148, 152, 151, 149]);

    $this->artisan('kami:bench', ['path' => '/about'])
        ->expectsOutputToContain('9.5 ms')
        ->expectsOutputToContain('150.0 ms')
        ->doesntExpectOutputToContain('budget')
        ->assertSuccessful();

    // One more, the page warmed up.
    Http::assertSentCount(21);
    Http::assertSent(fn (Request $request) => $request->url() === 'http://localhost/about');
    Http::assertSent(fn (Request $request) => $request->url() === 'http://localhost/about?kami-bench=10');
    expect(json_decode((string) file_get_contents(BenchCommand::resultPath()), true))
        ->toMatchArray(['url' => 'http://localhost/about', 'cached_ms' => 9.5, 'uncached_ms' => 150.0]);
});

it('records no Cached median for a page the page cache does not serve, and says why', function () {
    serving(array_fill(0, 10, 60.0), array_fill(0, 10, 150.0), hits: 0);

    // One write can carry several lines, which the command's output expectations would each claim.
    expect(Artisan::call('kami:bench'))->toBe(0)
        ->and(Artisan::output())->toMatch('/Cached\W+not served from the page cache/')
        ->toContain('a route of the site', 'Kami::withoutPageCache()', 'Authorization header', 'global middleware')
        ->toMatch('/Uncached\W+150\.0 ms/')
        ->not->toContain('60.0 ms', '30 ms budget');

    expect(json_decode((string) file_get_contents(BenchCommand::resultPath()), true))
        ->toMatchArray(['cached_ms' => null, 'uncached_ms' => 150.0]);

    Artisan::call('kami:doctor');
    expect(Artisan::output())->toMatch('/Cached\W+not served from the page cache/')
        ->toMatch('/Uncached\W+150\.0 ms/')
        ->not->toContain('60.0 ms');
});

it('records no Cached median when one response of the cached run is not the page cache\'s', function () {
    serving(array_fill(0, 10, 8.0), array_fill(0, 10, 150.0), hits: 9);

    $this->artisan('kami:bench')
        ->expectsOutputToContain('not served from the page cache')
        ->doesntExpectOutputToContain('8.0 ms')
        ->assertSuccessful();
});

it('takes the page cache\'s ETag as a web server compressing the page sends it', function (string $etag) {
    serving(array_fill(0, 10, 8.0), array_fill(0, 10, 150.0), hits: 0);
    Http::globalMiddleware(fn (callable $handler) => fn ($request, array $options) => $handler($request, $options)->then(
        fn ($response) => str_contains((string) $request->getUri(), 'kami-bench=') ? $response : $response->withHeader('ETag', $etag),
    ));

    $this->artisan('kami:bench')
        ->expectsOutputToContain('8.0 ms')
        ->doesntExpectOutputToContain('not served')
        ->assertSuccessful();
})->with([
    'Apache, suffixed' => '"0123456789abcdef-' . str_repeat('a', 32) . '-gzip"',
    'nginx, weakened' => 'W/"0123456789abcdef-' . str_repeat('a', 32) . '"',
]);

it('takes a site route\'s own ETag for no page cache hit', function () {
    serving(array_fill(0, 10, 8.0), array_fill(0, 10, 150.0), hits: 0);
    Http::globalMiddleware(fn (callable $handler) => fn ($request, array $options) => $handler($request, $options)->then(
        fn ($response) => $response->withHeader('ETag', '"' . md5('page') . '"'),
    ));

    $this->artisan('kami:bench')
        ->expectsOutputToContain('not served from the page cache')
        ->assertSuccessful();
});

it('warns above the budgets without failing', function () {
    serving(array_fill(0, 10, 31.0), array_fill(0, 10, 201.0));

    $this->artisan('kami:bench')
        ->expectsOutputToContain('above the 30 ms budget')
        ->expectsOutputToContain('above the 200 ms budget')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->url() === 'http://localhost/');
});

it('measures nothing when the page does not answer 200', function () {
    Http::fake(fn () => Http::response('', 401));

    $this->artisan('kami:bench')
        ->expectsOutputToContain('http://localhost/ answers 401')
        ->assertSuccessful();

    expect(BenchCommand::resultPath())->not->toBeFile();
});

it('measures nothing when the site cannot be reached', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    $this->artisan('kami:bench')
        ->expectsOutputToContain('Connection refused')
        ->assertSuccessful();

    expect(BenchCommand::resultPath())->not->toBeFile();
});

it('measures nothing when the HTTP client gives no transfer statistics, as without ext-curl', function () {
    Http::fake();

    $this->artisan('kami:bench')
        ->expectsOutputToContain('load the PHP extension curl')
        ->assertSuccessful();

    expect(BenchCommand::resultPath())->not->toBeFile();
});

it('signs in with Digest behind a staging password, asked for and never an argument', function () {
    // The page cache stores no request with an Authorization header: a staging password never measures a cached page.
    serving(array_fill(0, 10, 100.0), array_fill(0, 10, 150.0), user: 'ada', hits: 0);

    $this->artisan('kami:bench', ['--user' => 'ada'])
        ->expectsQuestion('Password of ada', 'secret')
        ->expectsOutputToContain('not served from the page cache')
        ->expectsOutputToContain('150.0 ms')
        ->assertSuccessful();
});

it('shows its last result in the doctor, or that it never ran', function () {
    Artisan::call('kami:doctor');
    expect(Artisan::output())->toMatch('/Page speed\W+never measured, run kami:bench/');

    File::ensureDirectoryExists(dirname(BenchCommand::resultPath()));
    File::put(BenchCommand::resultPath(), '{"measured_at": "yesterday", "url": "http://localhost/", "cached_ms": 8, "uncached_ms": 150}');
    Artisan::call('kami:doctor');
    expect(Artisan::output())->toMatch('/Page speed\W+never measured, run kami:bench/');

    serving(array_fill(0, 10, 8.0), array_fill(0, 10, 150.0));
    Artisan::call('kami:bench', ['path' => '/about']);
    Artisan::call('kami:doctor');

    expect(Artisan::output())->toMatch('/Page speed\W+http:\/\/localhost\/about, \d{4}-\d\d-\d\d \d\d:\d\d UTC/')
        ->toMatch('/Cached\W+8\.0 ms/')
        ->toMatch('/Uncached\W+150\.0 ms/');
});
