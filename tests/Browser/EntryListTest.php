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

    assertAxeClean(signIn()
        ->navigate('/admin/articles')
        ->assertSee('Brouillon'), admin: true)
        ->assertNoJavaScriptErrors();
});

it('shows a site\'s own form and table', function () {
    resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Esquisse'], locale: 'fr');

    $page = assertAxeClean(signIn()
        ->navigate('/admin/projects')
        ->assertSee('No client'), admin: true);
    assertAxeClean($page
        ->navigate('/admin/projects/create')
        ->assertSee('Drafts stay private until published.'), admin: true)
        ->assertNoJavaScriptErrors();
});

it('moves a row with Tab and Enter alone, its control shown and focus kept', function () {
    $save = resolve(SaveEntry::class);

    foreach (['Premier', 'Second', 'Third'] as $title) {
        $save->handle(Project::class, ['title' => $title], locale: 'fr');
    }

    $focused = 'document.activeElement.textContent.trim()';
    $page = signIn()
        ->navigate('/admin/projects')
        ->click('internal:role=button[name="Reorder records"i]');
    $page->wait(1);
    assertAxeClean($page, admin: true);

    // From the toggle, Tab alone reaches the controls (WCAG 2.1.1); a row's disabled button is skipped.
    $page->keys('internal:role=button[name="Finish reordering records"i]', 'Tab');

    for ($i = 0; $i < 20 && $page->script($focused) !== 'Move Third up'; $i++) {
        $page->keys(':focus', 'Tab');
    }

    $page->assertScript($focused, 'Move Third up');
    // Shown, with a focus indicator (WCAG 2.4.7), and in reorder mode before any focus (WCAG 2.5.7).
    $page->assertScript("document.activeElement.matches(':focus-visible') && document.activeElement.getBoundingClientRect().width > 1", true);
    $page->assertScript("[...document.querySelectorAll('[data-table-reorder-direction]')].every((button) => button.checkVisibility() && button.getBoundingClientRect().width >= 24)", true);

    $page->keys(':focus', 'Enter');
    $page->wait(1);
    $page->assertScript($focused, 'Move Third up');
    $page->assertScript("document.activeElement.matches(':focus-visible')", true);
    $page->assertScript("document.querySelector('[x-ref=tableReorderStatus]').textContent", 'Third moved to position 2 of 3.');

    // Reaching the top disables the button pressed: its other one takes focus (WCAG 2.4.3).
    $page->keys(':focus', 'Enter');
    $page->wait(1);
    $page->assertScript($focused, 'Move Third down');
    $page->assertNoJavaScriptErrors();
});

it('names each selection checkbox with its row\'s title, whichever Locale holds it', function () {
    resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Esquisse'], locale: 'fr');
    resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Sketch'], locale: 'en');

    assertAxeClean(signIn()->navigate('/admin/projects')->assertSee('Sketch'), admin: true)
        ->assertVisible('internal:role=checkbox[name="Select/deselect item Esquisse for bulk actions."s]')
        ->assertVisible('internal:role=checkbox[name="Select/deselect item Sketch for bulk actions."s]')
        ->assertNoJavaScriptErrors();
});

it('writes in the rich text editor, and keeps a document holding a Block whole', function () {
    $body = ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Avant']]],
        ['type' => 'block', 'attrs' => ['handle' => 'gallery', 'data' => ['items' => ['01J']]]],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Voir la '], ['type' => 'text', 'text' => 'target', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'kami://entry/01JABC']]]]]],
    ]];
    $entry = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada', 'body' => $body], locale: 'fr');

    $page = assertAxeClean(signIn()
        ->navigate("/admin/articles/{$entry->id}/edit")
        ->assertSee('Avant')
        ->assertSee('Block unavailable for now: gallery'), admin: true)
        ->assertNoJavaScriptErrors();

    assertAxeClean(visit("/admin/articles/{$entry->id}/edit")->inDarkMode()->assertSee('Avant'), admin: true)->assertNoJavaScriptErrors();
    assertAxeClean(visit("/admin/articles/{$entry->id}/edit")->on()->mobile()->assertSee('Avant'), admin: true)->assertNoJavaScriptErrors();

    $page->click('internal:role=button[name="Link"s]');
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
        ->and(json_decode((string) DB::table('kami_type_article')->where(['entry_id' => $entry->id, 'locale' => 'fr'])->value('body'), true)['content'][2]['content'][1]['marks'][0]['attrs']['href'])->toBe('kami://entry/01JABC');
});
