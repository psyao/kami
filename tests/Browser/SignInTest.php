<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

it('keeps a password filled before the field\'s script loads, as a password manager does on page load (#128)', function () {
    // Without model events: a fixture, whose own `kami.users.created` row no test reads. No Role, so no MFA: the password alone signs in.
    User::withoutEvents(fn () => User::forceCreate(['name' => 'Grace Hopper', 'email' => 'grace@example.test', 'password' => Hash::make('secret-password')]));
    // Filament loads the password field's Alpine component after the page (`x-load`): held back here until the test lets it go, so the fill always comes first.
    File::prepend(public_path('js/filament/forms/components/text-input/password-reveal.js'), 'await new Promise((resolve) => { window.releasePasswordReveal = resolve; });');

    $page = visit('/admin/login', ['reducedMotion' => 'reduce'])
        ->fill('[id="form.email"]', 'grace@example.test')
        ->fill('[id="form.password"]', 'secret-password');

    expect($page->script("document.querySelector('[x-load][x-ignore]') !== null"))->toBeTrue();

    // Once the script is in, the component initialises and binds the field; the plugin times the wait out after 5 seconds.
    $page->script('new Promise(function release(resolve) { window.releasePasswordReveal ? (window.releasePasswordReveal(), resolve()) : requestAnimationFrame(() => release(resolve)); })');
    $page->script("new Promise(function wait(resolve) { document.querySelector('[x-load][x-ignore]') ? requestAnimationFrame(() => wait(resolve)) : resolve(); })");

    $page->assertValue('[id="form.password"]', 'secret-password')
        ->press('internal:role=button[name="Sign in"s]')
        ->assertSee('Dashboard')
        ->assertNoJavaScriptErrors();
});
