<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Psyao\Kami\Content\Entry;

uses(RefreshDatabase::class);

it('signs in, creates and publishes an Entry in two Locales, and reads both on the front', function () {
    $page = signIn();

    $page = assertAxeClean($page->navigate('/admin/articles/create'), admin: true);
    $page = assertAxeClean($page
        ->fill('[id="form.title"]', 'Bonjour')
        ->fill('[id="form.author"]', 'Ada Lovelace')
        ->press('internal:role=button[name="Publish"s]')
        ->assertSee('FR · Published'), admin: true);
    $page = assertAxeClean($page->press('internal:role=button[name="FR · Published"s]'), admin: true);
    $page = assertAxeClean($page
        ->click('internal:role=menuitem[name="EN · No translation yet"s]')
        ->assertSee('Translation: EN'), admin: true);
    assertAxeClean($page
        ->fill('[id="form.title"]', 'Hello')
        ->press('internal:role=button[name="Publish"s]')
        ->assertSee('View on the site')
        ->navigate('/admin/articles')
        ->assertSee('Bonjour'), admin: true)
        ->assertNoJavaScriptErrors();

    $entry = Entry::query()->sole();

    foreach (['/admin/articles', "/admin/articles/{$entry->id}/edit", "/admin/articles/{$entry->id}/edit/en"] as $url) {
        assertAxeClean(visit($url)->inDarkMode()->assertDontSee('Sign in'), admin: true)->assertNoJavaScriptErrors();
        assertAxeClean(visit($url)->on()->mobile()->assertDontSee('Sign in'), admin: true)->assertNoJavaScriptErrors();
    }

    assertAxeClean(visit('/articles/bonjour')->assertSee('Bonjour'))->assertNoJavaScriptErrors();
    assertAxeClean(visit('/en/articles/hello')->assertSee('Hello'))->assertNoJavaScriptErrors();
});
