<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Psyao\Kami\Content\Actions\SaveEntry;
use Workbench\App\Content\Article;

uses(RefreshDatabase::class);

it('opens the SEO section, counts the Meta title as one types, and keeps the edit screen accessible', function () {
    $entry = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada']);
    $page = signIn();

    assertAxeClean($page->navigate("/admin/articles/{$entry->id}/edit")
        ->press('SEO')
        ->assertSee('Hide from search engines')
        ->assertSee('0 characters, about 60 shown')
        ->fill('[id="form.meta_title"]', 'Un titre')
        ->assertSee('8 characters, about 60 shown'), admin: true)
        ->assertNoJavaScriptErrors();

    // Open, not only in the DOM.
    expect($page->script("document.getElementById('form.meta_title').checkVisibility()"))->toBeTrue();
});
