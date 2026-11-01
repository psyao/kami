<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Psyao\Kami\Content\Actions\SaveEntry;
use Workbench\App\Content\Article;
use Workbench\App\Content\Project;

uses(RefreshDatabase::class);

it('names every link of a list holding a Draft', function () {
    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Brouillon', 'author' => 'Ada'], locale: 'fr');

    signIn()
        ->navigate('/admin/articles')
        ->assertSee('Brouillon')
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors();
});

it('shows a site\'s own form and table', function () {
    resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Esquisse'], locale: 'fr');

    signIn()
        ->navigate('/admin/projects')
        ->assertSee('No client')
        ->assertNoAccessibilityIssues()
        ->navigate('/admin/projects/create')
        ->assertSee('Drafts stay private until published.')
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors();
});

it('keeps focus on the moved row\'s control after a keyboard move, and names the row', function () {
    $save = resolve(SaveEntry::class);

    foreach (['Premier', 'Second', 'Third'] as $title) {
        $save->handle(Project::class, ['title' => $title], locale: 'fr');
    }

    $focused = 'document.activeElement.textContent.trim()';
    $page = signIn()
        ->navigate('/admin/projects')
        ->click('internal:role=button[name="Reorder records"i]');

    $page->script("document.querySelectorAll('[data-table-reorder-direction=up]')[2].focus()");
    $page->keys(':focus', 'Enter');
    $page->wait(1);
    $page->assertScript($focused, 'Move Third up');
    $page->assertScript("document.querySelector('[x-ref=tableReorderStatus]').textContent", 'Third moved to position 2 of 3.');

    // Reaching the top disables the button pressed: its other one takes focus.
    $page->keys(':focus', 'Enter');
    $page->wait(1);
    $page->assertScript($focused, 'Move Third down');
    $page->assertNoJavaScriptErrors();
});
