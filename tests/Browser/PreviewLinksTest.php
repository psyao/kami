<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Psyao\Kami\Content\Actions\SaveEntry;
use Workbench\App\Content\Article;

uses(RefreshDatabase::class);

it('creates a link that shows the Draft, lists it in the dialog, and revokes it', function () {
    $entry = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Brouillon', 'author' => 'Ada']);

    $page = signIn()
        ->navigate("/admin/articles/{$entry->id}/edit")
        ->click('internal:role=button[name="Preview links"s]')
        ->assertSee('No active link.')
        ->assertNoAccessibilityIssues()
        ->click('internal:role=button[name="Create a link"s]')
        ->assertSee('Preview link created');

    // On the Site's URL, which is not the test server's: its path only.
    $path = $page->script("new URL(document.querySelector('.fi-modal-window input[readonly]').value).pathname");

    visit($path)->assertSee('Brouillon')->assertSee('Ce lien expire le')->assertNoJavaScriptErrors();

    $page->click('.fi-modal-footer-actions >> internal:role=button[name="Close"s]')
        ->click('internal:role=button[name="Preview links"s]')
        ->assertSee('created by Ada Lovelace')
        ->click('internal:role=button[name="Revoke"s]')
        ->click('.fi-modal-footer-actions >> internal:role=button[name="Confirm"s]')
        ->assertSee('Preview link revoked')
        ->assertNoJavaScriptErrors();

    visit($path)->assertSee('404');
});
