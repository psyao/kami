<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Facades\Kami;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Filament opens its panel to a User that is not a `FilamentUser` in the local environment only.
    config(['app.env' => 'local']);
    // The suite's array sessions end with each request: signing in needs one that lasts.
    config(['session.driver' => 'file']);
    Kami::routes();
});

it('signs in, creates and publishes an Entry in two Locales, and reads both on the front', function () {
    $user = User::forceCreate(['name' => 'Ada Lovelace', 'email' => 'ada@example.test', 'password' => Hash::make('secret-password')]);
    DB::table('kami_role_user')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->getKey(), 'role' => 'administrator']);

    visit('/admin/login')
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
