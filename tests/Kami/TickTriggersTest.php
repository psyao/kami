<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Facades\Kami;
use Workbench\App\Content\Article;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The test's own: parallel processes share the skeleton's storage/, where the Tick writes its `tick-*` files.
    $this->app->useStoragePath($this->storage = temporaryPath());
    $this->runs = [];
    $this->schedule = resolve(Schedule::class);
    config(['kami.tick.token' => 'planner-token']);
    Kami::routes();
});

afterEach(function (): void {
    File::deleteDirectory($this->storage);
});

/** The host's planner calling the tick URL at a given time. */
function plannerCall(object $test, string $time, string $method = 'get'): TestResponse
{
    $test->travelTo($time);

    return $test->{$method}('/kami/tick/planner-token');
}

it('answers 404 while the token is empty or differs', function (?string $configured, string $url): void {
    config(['kami.tick.token' => $configured]);
    hourlyProbe($this);

    $this->get($url)->assertNotFound();

    $this->assertDatabaseCount('kami_task_runs', 0);
})->with([
    'empty token' => ['', '/kami/tick/planner-token'],
    'KAMI_TICK_TOKEN=null' => [null, '/kami/tick/null'],
    'wrong token' => ['planner-token', '/kami/tick/planner-tokem'],
    'a prefix of the token' => ['planner-token', '/kami/tick/planner'],
]);

it('runs the overdue tasks inside the request and answers OK in plain text, from GET or POST', function (string $method): void {
    hourlyProbe($this);
    plannerCall($this, '2026-11-09 10:30:00', $method)->assertOk();

    $response = plannerCall($this, '2026-11-09 11:10:00', $method);

    $response->assertOk()->assertContent('OK')->assertHeader('Content-Type', 'text/plain; charset=utf-8')->assertHeader('Cache-Control', 'no-store, private');
    expect($this->runs)->toBe(['probe'])
        ->and(json_decode(File::get("{$this->storage}/framework/kami/tick-web"), true))->toMatchArray(['at' => '2026-11-09T11:10:00+00:00', 'result' => 'OK']);
})->with(['get', 'post']);

it('answers BUSY while another Tick holds the lock', function (): void {
    hourlyProbe($this);
    plannerCall($this, '2026-11-09 10:30:00');
    // The array store's lock expires on the travelled clock.
    $this->travelTo('2026-11-09 11:10:00');
    $lock = Cache::lock('kami:tick', 60);
    $lock->get();

    plannerCall($this, '2026-11-09 11:10:00')->assertOk()->assertContent('BUSY');

    expect($this->runs)->toBe([]);
    $lock->release();
});

it('answers 500 FAILED when a task fails', function (): void {
    Exceptions::fake();
    $this->schedule->call(fn () => throw new RuntimeException('Broken task.'))->name('failing')->hourly();
    plannerCall($this, '2026-11-09 10:30:00');

    plannerCall($this, '2026-11-09 11:10:00')->assertInternalServerError()->assertContent('FAILED');
});

it('answers 500 FAILED when the Tick cannot start, before kami:install', function (): void {
    Exceptions::fake();
    Schema::drop('kami_task_runs');

    plannerCall($this, '2026-11-09 10:30:00')->assertInternalServerError()->assertContent('FAILED');

    Exceptions::assertReported(QueryException::class);
    expect(json_decode(File::get("{$this->storage}/framework/kami/tick-web"), true))->toMatchArray(['result' => 'FAILED']);
});

// An `exec()` task starts a child process, which shared hosting refuses on the web: it waits for a CLI Tick, and `kami:doctor` names it.
it('skips an exec() task on the web', function (): void {
    $this->schedule->exec('touch', [$file = "{$this->storage}/touched"])->hourly();
    plannerCall($this, '2026-11-09 10:30:00');

    plannerCall($this, '2026-11-09 11:10:00')->assertContent('OK');

    expect($file)->not->toBeFile();
    $this->assertDatabaseEmpty('kami_task_runs');
});

/** `kami:tick:url`'s output, and the token on its `.env` line. */
function tickUrl(): array
{
    expect(Artisan::call('kami:tick:url'))->toBe(0);
    $output = Artisan::output();
    expect($output)->toMatch('/^KAMI_TICK_TOKEN=[A-Za-z0-9]{40}$/m');
    preg_match('/^KAMI_TICK_TOKEN=(\w+)$/m', $output, $line);

    return [$output, $line[1]];
}

it('prints a new token\'s .env line and its URL on the Site URL, which then ticks', function (): void {
    config(['kami.sites.default.url' => 'https://example.test']);

    [$output, $token] = tickUrl();

    expect($output)->toContain("https://example.test/kami/tick/{$token}");
    config(['kami.tick.token' => $token]);
    $this->get("/kami/tick/{$token}")->assertOk()->assertContent('OK');
});

it('generates another token at each call', function (): void {
    expect(tickUrl()[1])->not->toBe(tickUrl()[1]);
});

/** The `tick-*` file of a trigger, written `$age` seconds ago. */
function lastTick(object $test, string $trigger, int $age): void
{
    File::ensureDirectoryExists("{$test->storage}/framework/kami");
    touch("{$test->storage}/framework/kami/tick-{$trigger}", time() - $age);
}

function visitTicked(object $test): bool
{
    return is_file("{$test->storage}/framework/kami/tick-visit");
}

describe('a visit', function (): void {
    beforeEach(function (): void {
        resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Hello world', 'status' => 'published', 'author' => 'Ada Lovelace']);
        hourlyProbe($this);
    });

    it('starts a Tick after a front page or an admin page when no Tick ran yet', function (string $url): void {
        $this->get($url)->assertOk();

        expect(visitTicked($this))->toBeTrue()
            ->and(json_decode(File::get("{$this->storage}/framework/kami/tick-visit"), true))->toMatchArray(['result' => 'OK']);
        $this->assertDatabaseHas('kami_task_runs', ['description' => 'probe', 'last_status' => null]);
    })->with(['front page' => '/articles/hello-world', 'admin page' => '/admin/login']);

    it('starts a Tick once the last one is over five minutes old, whatever its trigger', function (string $trigger): void {
        lastTick($this, $trigger, 301);
        lastTick($this, 'web', 400);

        $this->get('/articles/hello-world')->assertOk();

        expect(visitTicked($this))->toBeTrue();
    })->with(['cli', 'visit']);

    it('leaves the Tick alone while any trigger ticked in the last five minutes', function (string $trigger): void {
        lastTick($this, 'web', 400);
        lastTick($this, $trigger, 240);

        $this->get('/articles/hello-world')->assertOk();

        expect(visitTicked($this))->toBe($trigger === 'visit')
            ->and(File::lastModified("{$this->storage}/framework/kami/tick-{$trigger}"))->toBeLessThanOrEqual(time() - 240);
        $this->assertDatabaseEmpty('kami_task_runs');
    })->with(['cli', 'web', 'visit']);

    // With the database down, a visit's failed Tick is reported once: its fresh `tick-visit` keeps the next visits quiet, as for any trigger,
    // and the page cache reader serving. One request: PostgreSQL aborts the test's transaction after the failed query.
    it('touches tick-visit with FAILED when the Tick cannot start', function (): void {
        Exceptions::fake();
        Schema::drop('kami_task_runs');

        $this->get('/articles/hello-world')->assertOk();

        expect(json_decode(File::get("{$this->storage}/framework/kami/tick-visit"), true))->toMatchArray(['result' => 'FAILED']);
        Exceptions::assertReportedCount(1);
    });

    it('starts no Tick on HEAD, an error status or the tick route', function (string $method, string $url, int $status): void {
        $this->call($method, $url)->assertStatus($status);

        expect(visitTicked($this))->toBeFalse();
    })->with([
        'HEAD' => ['HEAD', '/articles/hello-world', 200],
        'a 404' => ['GET', '/articles/missing', 404],
        'the tick route' => ['GET', '/kami/tick/planner-token', 200],
    ]);
});
