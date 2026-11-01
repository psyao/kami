<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Entry;
use Workbench\App\Content\Article;
use Workbench\App\Content\Project;

uses(RefreshDatabase::class);

it('names every link of a list holding a Draft and a Scheduled date', function () {
    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Brouillon', 'author' => 'Ada'], locale: 'fr');
    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'À paraître', 'author' => 'Ada', 'status' => 'published', 'published_at' => now()->addDay()], locale: 'fr');

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

it('writes in the rich text editor, and keeps a document holding a Block whole', function () {
    $body = ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Avant']]],
        ['type' => 'block', 'attrs' => ['handle' => 'gallery', 'data' => ['items' => ['01J']]]],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'target', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'kami://entry/01JABC']]]]]],
    ]];
    $entry = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada', 'body' => $body], locale: 'fr');

    $page = signIn()
        ->navigate("/admin/articles/{$entry->id}/edit")
        ->assertSee('Avant')
        ->assertSee('Block unavailable for now: gallery')
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors();

    visit("/admin/articles/{$entry->id}/edit")->inDarkMode()->assertSee('Avant')->assertNoAccessibilityIssues()->assertNoJavaScriptErrors();
    visit("/admin/articles/{$entry->id}/edit")->on()->mobile()->assertSee('Avant')->assertNoAccessibilityIssues()->assertNoJavaScriptErrors();

    $page->click('internal:role=button[name="Link"i]');
    $page->assertSee('URL')->assertDontSee('Open in new tab');
    $page->click('internal:role=button[name="Cancel"i] >> nth=-1');

    $page->click('[data-testid="rich-editor-content"] >> text=Avant');
    $page->keys('[data-testid="rich-editor-content"]', str_split('Encore'));
    $page->wait(1);
    $page->click('internal:role=button[name="Save changes"i]');
    $page->wait(2);

    $page->assertSee('AvantEncore')->assertSee('Block unavailable for now: gallery')->assertNoJavaScriptErrors();

    $saved = Entry::query()->withUnpublished()->findOrFail($entry->id)->body;

    expect($saved->blocks())->toBe([['handle' => 'gallery', 'data' => ['items' => ['01J']]]])
        ->and($saved->text())->toContain('Encore')->toContain('Avant')->toContain('target')
        ->and(json_decode((string) DB::table('kami_type_article')->where(['entry_id' => $entry->id, 'locale' => 'fr'])->value('body'), true)['content'][2]['content'][0]['marks'][0]['attrs']['href'])->toBe('kami://entry/01JABC');
});
