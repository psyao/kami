<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Livewire\Component;
use Livewire\Livewire;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Facades\Kami;
use Psyao\Kami\Forms\Submission;
use Symfony\Component\HttpFoundation\Response;
use Workbench\App\Content\Article;

uses(RefreshDatabase::class);

/** A type with no Field whose page renders a site's own Livewire component, as `docs/guide/front-stacks.md` shows it. */
final class LivewirePage extends ContentType
{
    public static string $body = '<livewire:counter />';

    public function render(Entry $entry): Response
    {
        return response(Blade::render('<html><head></head><body>' . self::$body . '</body></html>', ['entry' => $entry]));
    }
}

final class Counter extends Component
{
    public int $count = 0;

    public function increment(): void
    {
        $this->count++;
    }

    public function render(): string
    {
        return '<button type="button" wire:click="increment">{{ $count }}</button>';
    }
}

/** Laravel's origin check as a browser meets it: the framework skips it under unit tests. */
final class PreventRequestForgeryInTests extends PreventRequestForgery
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}

beforeEach(function () {
    // A `storage/` of the test's own: parallel processes share the skeleton's.
    $this->app->useStoragePath(temporaryPath());
    // Livewire's assets as `kami:install` publishes them, so its script tag names the build it loads.
    $this->app->usePublicPath(temporaryPath());
    withLivewireAssets();
    config(['kami.page_cache.enabled' => true, 'kami.types' => [Article::class, LivewirePage::class]]);
    Livewire::component('counter', Counter::class);
    registerGuideContact();
    LivewirePage::$body = '<livewire:counter />';
});

/** The published page of a `LivewirePage`, on the mount asked. */
function livewirePage(bool $session): Entry
{
    Kami::routes(session: $session);

    return resolve(SaveEntry::class)->handle(LivewirePage::class, ['title' => 'Hello world', 'status' => 'published'], locale: 'fr');
}

/**
 * The update Livewire's script sends when a visitor acts on the component of `$page`, with no cookie.
 *
 * @param  array<string, string>  $headers
 * @param  array<string, mixed>  $updates
 */
function livewireUpdate(TestResponse $page, array $headers, string $method, array $updates = []): TestResponse
{
    preg_match('/wire:snapshot="([^"]+)"/', (string) $page->getContent(), $snapshot);

    return test()->withHeaders(['X-Livewire' => 'true', ...$headers])->postJson(app('livewire')->getUpdateUri(), ['components' => [[
        'snapshot' => html_entity_decode($snapshot[1]),
        'updates' => $updates,
        'calls' => [['method' => $method, 'params' => [], 'metadata' => []]],
    ]]]);
}

$mounts = ['the default mount' => [false], 'the session mount' => [true]];

it('serves Livewire\'s CSP build without its progress bar under the front policy, the cached page keeping them, and the full build elsewhere', function (bool $session) {
    livewirePage($session);
    $counter = fn () => Blade::render('<html><head></head><body><livewire:counter /></body></html>');
    // A page of the site's own under Kami's policy: the Workbench appends `SecurityHeaders` to the `web` group, as the starter kit does.
    Route::middleware('web')->get('/site-page', $counter);
    // A route outside Kami's policy, as Filament's panel is.
    Route::get('/elsewhere', $counter);

    $first = $this->get('/livewire_page/hello-world')->assertOk();
    // Livewire injects its assets once per request; production builds that state per request, a test per test.
    Livewire::flushState();
    $again = $this->get('/livewire_page/hello-world')->assertOk();
    Livewire::flushState();

    expect($first->getContent())->toMatch('#<script src="[^"]*/vendor/livewire/livewire\.csp(\.min)?\.js\?id=[^"]*"[^>]* data-no-progress-bar#')
        ->and($again->getContent())->toMatch('#/vendor/livewire/livewire\.csp(\.min)?\.js#');
    // Only the default mount's pages are cached: the session mount renders each, with the visitor's token.
    $session ? $again->assertHeaderMissing('ETag') : expect($again->getContent())->toBe($first->getContent());
    expect($this->get('/site-page')->getContent())->toMatch('#/vendor/livewire/livewire\.csp(\.min)?\.js#');
    Livewire::flushState();
    expect($this->get('/elsewhere')->getContent())->toMatch('#/vendor/livewire/livewire(\.min)?\.js#')->not->toContain('data-no-progress-bar');
})->with($mounts);

it('leaves Livewire\'s full build to a site whose front policy allows \'unsafe-eval\'', function () {
    config(['kami.security.csp' => ['script-src' => ["'unsafe-eval'"]]]);
    livewirePage(false);

    expect($this->get('/livewire_page/hello-world')->assertOk()->getContent())
        ->toMatch('#/vendor/livewire/livewire(\.min)?\.js#')->not->toContain('data-no-progress-bar');
});

it('runs a cookie-less update from the same origin, setting the session cookie, and refuses one from another site with a 419', function (bool $session, ?string $site, int $status) {
    livewirePage($session);
    $page = $this->get('/livewire_page/hello-world');
    $this->app->bind(PreventRequestForgery::class, PreventRequestForgeryInTests::class);

    $update = livewireUpdate($page, $site === null ? [] : ['Sec-Fetch-Site' => $site], 'increment')->assertStatus($status);

    if ($status === 200) {
        expect($update->json('components.0.effects.html'))->toContain('>1</button>');
        $update->assertCookie(config('session.cookie'));
    }
})->with($mounts)->with([
    'same-origin' => ['same-origin', 200],
    'cross-site' => ['cross-site', 419],
    'same-site' => ['same-site', 419],
    // A cached page carries no valid token, so a browser without Fetch Metadata cannot prove its origin.
    'without Fetch Metadata' => [null, 419],
]);

it('stores a Submission through SubmitForm from a Livewire action', function (bool $session) {
    LivewirePage::$body = '<livewire:contact :entry="$entry" />';
    $entry = livewirePage($session);
    $page = $this->get('/livewire_page/hello-world');

    livewireUpdate($page, ['Sec-Fetch-Site' => 'same-origin'], 'send', ['name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'message' => 'Bonjour, parlons de votre projet.'])
        ->assertOk();

    expect(Submission::query()->sole()->only(['locale', 'entry_id', 'data']))->toBe([
        'locale' => 'fr',
        'entry_id' => $entry->id,
        'data' => ['name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'message' => 'Bonjour, parlons de votre projet.'],
    ]);
})->with($mounts);
