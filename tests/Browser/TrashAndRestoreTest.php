<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Psyao\Kami\Content\Actions\SaveEntry;
use Workbench\App\Content\Article;

uses(RefreshDatabase::class);

it('trashes an Entry from its row, finds it through the Trash filter, and restores it', function () {
    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada', 'status' => 'published'], locale: 'fr');

    $page = signIn()
        ->navigate('/admin/articles')
        ->click('internal:role=button[name="Move to Trash"s]')
        ->assertSee('Move Bonjour to the Trash')
        ->assertNoAccessibilityIssues()
        ->click('.fi-modal-footer-actions >> internal:role=button[name="Move to Trash"s]')
        ->assertSee('Moved to the Trash')
        ->assertSee('No Articles');

    assertAxeClean(visit('/articles/bonjour')->assertSee('404'));

    $page->click('internal:role=button[name="Filter"s]')
        ->select('Trash', 'In the Trash')
        ->click('internal:role=button[name="Apply filters"s]')
        ->assertSee('Bonjour')
        // Applying keeps the dropdown open under the pointer: axe would measure the button's hover colours, or the panel mid-fade (#361).
        ->keys('[data-table-filter-action="apply"]', 'Escape');
    $page->script("() => new Promise((resolve, reject) => { const until = Date.now() + 5000; const hidden = () => ! document.querySelector('[data-table-filter-action=\"apply\"]').checkVisibility() ? resolve() : Date.now() > until ? reject(new Error('The filter dropdown is still open after 5 seconds.')) : requestAnimationFrame(hidden); hidden(); })");

    $page->assertNoAccessibilityIssues()
        ->click('internal:role=button[name="Restore"s]')
        ->click('.fi-modal-footer-actions >> internal:role=button[name="Restore"s]')
        ->assertSee('Restored')
        ->assertNoJavaScriptErrors();

    visit('/articles/bonjour')->assertSee('Bonjour')->assertNoJavaScriptErrors();
});
