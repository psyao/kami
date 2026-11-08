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
 * Answers 200 with these server times, in ms, by whether the request carries `?kami-bench=`, as cURL's transfer statistics.
 * With a user, a request not signed in as them gets a Digest challenge first, as a staging password answers.
 *
 * @param  list<float>  $cached
 * @param  list<float>  $uncached
 */
function serving(array $cached, array $uncached, ?string $user = null): void
{
    Http::fake(function (Request $request, array $options) use (&$cached, &$uncached, $user) {
        if ($user !== null && ! str_starts_with($request->header('Authorization')[0] ?? '', "Digest username=\"{$user}\"")) {
            return Http::response('', 401, ['WWW-Authenticate' => 'Digest realm="staging", nonce="0123456789abcdef", qop="auth"']);
        }
        $server = str_contains($request->url(), 'kami-bench=') ? array_shift($uncached) : array_shift($cached);
        $options['on_stats'](new TransferStats($request->toPsrRequest(), null, null, null, ['appconnect_time' => 0.04, 'starttransfer_time' => 0.04 + $server / 1000]));

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

    Http::assertSentCount(20);
    Http::assertSent(fn (Request $request) => $request->url() === 'http://localhost/about');
    Http::assertSent(fn (Request $request) => $request->url() === 'http://localhost/about?kami-bench=10');
    expect(json_decode((string) file_get_contents(BenchCommand::resultPath()), true))
        ->toMatchArray(['url' => 'http://localhost/about', 'cached_ms' => 9.5, 'uncached_ms' => 150.0]);
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
    serving(array_fill(0, 10, 8.0), array_fill(0, 10, 150.0), user: 'ada');

    $this->artisan('kami:bench', ['--user' => 'ada'])
        ->expectsQuestion('Password of ada', 'secret')
        ->expectsOutputToContain('150.0 ms')
        ->assertSuccessful();
});

it('shows its last result in the doctor, or that it never ran', function () {
    Artisan::call('kami:doctor');
    expect(Artisan::output())->toMatch('/Page speed\W+never measured, run kami:bench/');

    File::ensureDirectoryExists(dirname(BenchCommand::resultPath()));
    File::put(BenchCommand::resultPath(), '{"measured_at": "yesterday-ish", "url": "http://localhost/", "cached_ms": 8, "uncached_ms": 150}');
    Artisan::call('kami:doctor');
    expect(Artisan::output())->toMatch('/Page speed\W+never measured, run kami:bench/');

    serving(array_fill(0, 10, 8.0), array_fill(0, 10, 150.0));
    Artisan::call('kami:bench', ['path' => '/about']);
    Artisan::call('kami:doctor');

    expect(Artisan::output())->toMatch('/Page speed\W+http:\/\/localhost\/about, \d{4}-\d\d-\d\d \d\d:\d\d UTC/')
        ->toMatch('/Cached\W+8\.0 ms/')
        ->toMatch('/Uncached\W+150\.0 ms/');
});
