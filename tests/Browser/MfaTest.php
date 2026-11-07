<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

it('sets MFA up from the QR code\'s secret, then asks a code at the next sign-in', function () {
    // Without model events: a fixture, whose own `kami.users.created` row no test reads.
    $user = User::withoutEvents(fn () => User::forceCreate(['name' => 'Grace Hopper', 'email' => 'grace@example.test', 'password' => Hash::make('secret-password')]));
    DB::table('kami_role_user')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->getKey(), 'role' => 'administrator']);

    $page = visit('/admin/login', ['reducedMotion' => 'reduce']);
    signInPassword($page, 'grace@example.test')
        // A manager without MFA lands on its set-up, not on the dashboard.
        ->assertPathIs('/admin/multi-factor-authentication/set-up')
        // Reached by a redirect, which nothing waits for: pressed before Livewire starts, "Set up" opens nothing (#369).
        ->waitForEvent('load')
        ->press('internal:role=button[name="Set up"s]')
        ->assertSee('Or enter this code manually');

    // The secret shown as text beside the QR code, as a User types it into an app that cannot scan.
    preg_match('/\b[A-Z2-7]{16}\b/', $page->content(), $secret);

    $page->fill('[id="mountedActionSchema0.code"]', (new Google2FA)->getCurrentOtp($secret[0]))
        ->fill('[id="mountedActionSchema0.password"]', 'secret-password')
        ->press('Next')
        ->assertSee('recovery codes')
        ->press('Enable authenticator app')
        ->assertSee('Authenticator app has been enabled')
        ->assertNoJavaScriptErrors();

    expect($user->fresh()->getAppAuthenticationSecret())->toBe($secret[0]);

    // Signed out, then in again: the password alone no longer opens the admin. Set-up kept its code reusable, so the same 30 seconds do.
    $page->press('Continue')
        ->assertSee('Dashboard')
        ->click('internal:role=button[name="User menu"s]')
        ->press('Sign out')
        ->assertPathIs('/admin/login');

    passSignIn($page, 'grace@example.test', $secret[0])->assertSee('Dashboard')->assertNoJavaScriptErrors();
});

it('passes axe on the set-up and challenge pages, light, dark and mobile', function () {
    // Without model events: fixtures, whose own `kami.users.created` rows no test reads.
    $manager = User::withoutEvents(fn () => User::forceCreate(['name' => 'Grace Hopper', 'email' => 'grace@example.test', 'password' => Hash::make('secret-password')]));
    DB::table('kami_role_user')->insert(['id' => (string) Str::ulid(), 'user_id' => $manager->getKey(), 'role' => 'administrator']);
    withMfa(User::withoutEvents(fn () => User::forceCreate(['name' => 'Ada Lovelace', 'email' => 'ada@example.test', 'password' => Hash::make('secret-password')])));

    $modes = [
        'light' => fn (string $url) => visit($url, ['reducedMotion' => 'reduce']),
        'dark' => fn (string $url) => visit($url, ['reducedMotion' => 'reduce'])->inDarkMode(),
        'mobile' => fn (string $url) => visit($url, ['reducedMotion' => 'reduce'])->on()->mobile(),
    ];

    // The challenge, before anyone signs in: the browser keeps one session for the whole test.
    foreach ($modes as $mode) {
        signInPassword($mode('/admin/login'), 'ada@example.test')
            ->assertSee('Enter the 6-digit code from the authenticator app')
            ->assertNoAccessibilityIssues()
            ->assertNoJavaScriptErrors();
    }

    signInPassword($modes['light']('/admin/login'), 'grace@example.test')->assertPathIs('/admin/multi-factor-authentication/set-up');

    foreach ($modes as $mode) {
        $page = $mode('/admin/multi-factor-authentication/set-up')
            ->assertNoAccessibilityIssues()
            ->press('internal:role=button[name="Set up"s]')
            ->assertSee('Or enter this code manually');
        // The modal shows before Livewire's request ends, which dims "Set up" (`fi-disabled`) until then: axe measures it as the User sees it after (#369).
        $page->script("() => new Promise((resolve, reject) => { const until = Date.now() + 5000; const settled = () => ! [...document.querySelectorAll('.fi-link[aria-disabled=\"true\"]')].some((link) => link.textContent.trim() === 'Set up') ? resolve() : Date.now() > until ? reject(new Error('Set up is still disabled after 5 seconds.')) : requestAnimationFrame(settled); settled(); })");
        $page->assertNoAccessibilityIssues()
            ->assertNoJavaScriptErrors();
    }
});
