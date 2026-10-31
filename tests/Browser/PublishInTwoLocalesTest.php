<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Psyao\Kami\Content\Entry;

uses(RefreshDatabase::class);

it('signs in, creates and publishes an Entry in two Locales, and reads both on the front', function () {
    $page = signIn();

    $page
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
        visit($url)->inDarkMode()->assertDontSee('Sign in')->assertNoAccessibilityIssues()->assertNoJavaScriptErrors();
        visit($url)->on()->mobile()->assertDontSee('Sign in')->assertNoAccessibilityIssues()->assertNoJavaScriptErrors();
    }

    visit('/articles/bonjour')->assertSee('Bonjour')->assertNoAccessibilityIssues(3)->assertNoJavaScriptErrors();
    visit('/en/articles/hello')->assertSee('Hello')->assertNoAccessibilityIssues(3)->assertNoJavaScriptErrors();
});
