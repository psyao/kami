<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Psyao\Kami\Content\Entry;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

it('signs in, creates and publishes an Entry in two Locales, and reads both on the front', function () {
    $user = User::forceCreate(['name' => 'Ada Lovelace', 'email' => 'ada@example.test', 'password' => Hash::make('secret-password')]);
    DB::table('kami_role_user')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->getKey(), 'role' => 'administrator']);

    // Filament's theme drops its transitions under reduced motion, so axe never measures a notification mid-fade.
    $page = visit('/admin/login', ['reducedMotion' => 'reduce']);

    // Filament loads the password field's Alpine component after the page (`x-load`, `x-ignore` until it arrives):
    // its `wire:model` then resets the field to the empty Livewire state, wiping a password typed before.
    // The plugin times the script out after 5 seconds, failing here if a component never loads.
    // ponytail: tied to Filament's `x-load`/`x-ignore` markup; lifted when pest-plugin-browser exposes `waitForFunction`
    // or Filament keeps typed input, and moved to tests/Pest.php when a second Browser test signs in.
    $page->script("new Promise(function wait(resolve) { document.querySelector('[x-load][x-ignore]') ? requestAnimationFrame(() => wait(resolve)) : resolve(); })");

    $page
        ->fill('[id="form.email"]', 'ada@example.test')
        ->fill('[id="form.password"]', 'secret-password')
        ->press('internal:role=button[name="Sign in"s]')
        ->assertSee('Dashboard')
        ->navigate('/admin/articles/create')
        ->assertNoAccessibilityIssues()
        ->fill('[id="form.title"]', 'Bonjour')
        ->fill('[id="form.author"]', 'Ada Lovelace')
        ->press('internal:role=button[name="Publish"s]')
        ->assertSee('Add EN')
        ->assertNoAccessibilityIssues()
        ->click('internal:role=link[name="Add EN"s]')
        ->assertSee('Translation: EN')
        ->assertNoAccessibilityIssues()
        ->fill('[id="form.title"]', 'Hello')
        ->press('internal:role=button[name="Publish"s]')
        ->assertSee('View on the site')
        ->navigate('/admin/articles')
        ->assertSee('Bonjour')
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors();

    $entry = Entry::query()->sole();

    foreach (['/admin/articles', "/admin/articles/{$entry->id}/edit", "/admin/articles/{$entry->id}/edit/en"] as $url) {
        visit($url)->inDarkMode()->assertNoAccessibilityIssues()->assertNoJavaScriptErrors();
        visit($url)->on()->mobile()->assertNoAccessibilityIssues()->assertNoJavaScriptErrors();
    }

    visit('/articles/bonjour')->assertSee('Bonjour')->assertNoAccessibilityIssues(3)->assertNoJavaScriptErrors();
    visit('/en/articles/hello')->assertSee('Hello')->assertNoAccessibilityIssues(3)->assertNoJavaScriptErrors();
});
