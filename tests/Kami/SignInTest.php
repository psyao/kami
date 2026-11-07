<?php

declare(strict_types=1);

use Filament\Auth\Pages\Login as FilamentLogin;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Psyao\Kami\Admin\KamiPlugin;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

beforeEach(function () {
    // The panel admits a User that is not a FilamentUser in the local environment only.
    config(['app.env' => 'local']);
    // Over HTTP, as a visitor signs in: an Audit event without an actor is then the system's, not the command line's.
    (fn () => $this->isRunningInConsole = false)->call(app());
});

/** The sign-in page the admin panel serves, as Filament routes it, reached from the address. */
function signInPage(string $ip = '203.0.113.1'): Testable
{
    Filament::setCurrentPanel('admin');
    // Livewire's test requests all come from 127.0.0.1: each of this page's requests now comes from the address.
    Event::forget(RouteMatched::class);
    Event::listen(RouteMatched::class, fn (RouteMatched $event) => $event->request->server->set('REMOTE_ADDR', $ip));

    return Livewire::test(Filament::getPanel('admin')->getLoginRouteAction());
}

function signInUser(): User
{
    // Without model events: the User's own `kami.users.created` row is not what this file reads.
    return User::withoutEvents(fn () => User::forceCreate(['name' => 'Grace', 'email' => 'grace@example.test', 'password' => Hash::make('secret-password')]));
}

/** @return list<array<string, mixed>> */
function auditRows(): array
{
    return DB::table('kami_audit_events')->orderBy('id')->get(['event', 'user_id', 'actor', 'subject_type', 'subject_id', 'context'])
        ->map(fn (object $row) => [...(array) $row, 'context' => json_decode((string) $row->context, true)])->all();
}

function throttledTitle(): string
{
    return __('filament-panels::auth/pages/login.notifications.throttled.title', ['seconds' => 60, 'minutes' => 1]);
}

it('refuses a sixth attempt on one email from as many addresses, whatever its case, saying when to retry', function () {
    signInUser();

    foreach (['grace@example.test', 'Grace@example.test', 'GRACE@example.test', 'grace@Example.test', 'grace@example.TEST'] as $index => $email) {
        signInPage("203.0.113.{$index}")->fillForm(['email' => $email, 'password' => 'wrong'])->call('authenticate')
            ->assertHasFormErrors(['email' => __('filament-panels::auth/pages/login.messages.failed')]);
    }

    signInPage('198.51.100.1')->fillForm(['email' => 'grace@example.test', 'password' => 'secret-password'])->call('authenticate')
        ->assertNotified(throttledTitle());

    expect(Filament::auth()->check())->toBeFalse();
});

it('records each failed sign-in of the account, then one lockout naming the email key, never the address', function () {
    $grace = signInUser();

    for ($attempt = 0; $attempt < 7; $attempt++) {
        signInPage("203.0.113.{$attempt}")->fillForm(['email' => 'grace@example.test', 'password' => 'wrong'])->call('authenticate');
    }

    $failed = ['event' => 'kami.auth.sign-in-failed', 'user_id' => null, 'actor' => 'system', 'subject_type' => 'user', 'subject_id' => $grace->getKey(), 'context' => []];
    expect(auditRows())->toEqual([
        ...array_fill(0, 5, $failed),
        // The sixth attempt is the window's first refused; the seventh records nothing.
        ['event' => 'kami.auth.locked-out', 'user_id' => null, 'actor' => 'system', 'subject_type' => null, 'subject_id' => null, 'context' => ['key' => 'email']],
    ]);
});

it('refuses a sixth attempt from one address on as many emails, and records the lockout of the IP key', function () {
    foreach (range(1, 5) as $index) {
        signInPage()->fillForm(['email' => "nobody{$index}@example.test", 'password' => 'wrong'])->call('authenticate')
            ->assertHasFormErrors(['email' => __('filament-panels::auth/pages/login.messages.failed')]);
    }

    signInPage()->fillForm(['email' => 'nobody6@example.test', 'password' => 'wrong'])->call('authenticate')
        ->assertNotified(throttledTitle());

    // Unknown addresses record nothing of their own.
    expect(auditRows())->toEqual([
        ['event' => 'kami.auth.locked-out', 'user_id' => null, 'actor' => 'system', 'subject_type' => null, 'subject_id' => null, 'context' => ['key' => 'ip']],
    ]);
});

it('answers a wrong password and an unknown email with the same message', function () {
    signInUser();

    foreach (['grace@example.test', 'nobody@example.test'] as $index => $email) {
        signInPage("203.0.113.{$index}")->fillForm(['email' => $email, 'password' => 'wrong'])->call('authenticate')
            ->assertHasFormErrors(['email' => __('filament-panels::auth/pages/login.messages.failed')]);
    }
});

it('records a sign-in, with the User as subject and actor', function () {
    $grace = signInUser();

    signInPage()->fillForm(['email' => 'grace@example.test', 'password' => 'secret-password'])->call('authenticate')->assertHasNoFormErrors();

    expect(Filament::auth()->id())->toBe($grace->getKey())
        ->and(auditRows())->toEqual([
            ['event' => 'kami.auth.signed-in', 'user_id' => $grace->getKey(), 'actor' => 'user', 'subject_type' => 'user', 'subject_id' => $grace->getKey(), 'context' => []],
        ]);
});

it('records nothing of another guard\'s sign-in, or of a lockout another page fired', function () {
    config(['auth.guards.front' => ['driver' => 'session', 'provider' => 'users']]);
    $grace = signInUser();

    auth('front')->login($grace);
    event(new Lockout(request()));

    expect(auditRows())->toBe([]);
});

it('serves Kami\'s page whether the panel enables sign-in before or after the plugin, and keeps a host\'s own page', function () {
    $host = (new class extends FilamentLogin {})::class;
    $panels = [
        'before' => Panel::make()->id('before')->login()->plugin(KamiPlugin::make()),
        'after' => Panel::make()->id('after')->plugin(KamiPlugin::make())->login(),
        'host' => Panel::make()->id('host')->login($host)->plugin(KamiPlugin::make()),
    ];

    // What Filament's route file does for each panel, before it reads the sign-in page's class.
    foreach ($panels as $panel) {
        foreach ($panel->getRoutes() as $routes) {
            $routes($panel);
        }
    }

    expect($panels['before']->getLoginRouteAction())->not->toBe(FilamentLogin::class)->toBe(Filament::getPanel('admin')->getLoginRouteAction())
        ->and($panels['after']->getLoginRouteAction())->toBe($panels['before']->getLoginRouteAction())
        ->and($panels['host']->getLoginRouteAction())->toBe($host);
});
