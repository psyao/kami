<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Psyao\Kami\Content\Actions\SaveEntry;
use Workbench\App\Content\Article;

uses(RefreshDatabase::class);

/** The text of the tab named `kami-preview` once it shows `$text`, read from the admin, which shares its origin; the tab it found stays in `window.kamiTab`. */
function previewTabText(mixed $page, string $text): string
{
    return $page->script(<<<JS
        new Promise(function wait(resolve) {
            const tab = window.open('', 'kami-preview');
            window.kamiTab ??= tab;
            const body = tab.document.body ? tab.document.body.innerText : '';
            body.includes('{$text}') ? resolve(body) : setTimeout(() => wait(resolve), 50);
        })
        JS);
}

it('opens the unsaved form in a tab on the click, and refreshes the same tab on the next one', function () {
    $entry = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada']);
    $page = signIn();

    $page->navigate("/admin/articles/{$entry->id}/edit")
        ->assertNoAccessibilityIssues()
        ->fill('[id="form.title"]', 'Pas encore enregistré')
        ->press('internal:role=button[name="Preview"s]');

    expect(previewTabText($page, 'Pas encore enregistré'))->toContain('Prévisualisation de modifications non enregistrées');

    $page->fill('[id="form.title"]', 'Toujours pas enregistré')->press('internal:role=button[name="Preview"s]');

    expect(previewTabText($page, 'Toujours pas enregistré'))->toContain('Toujours pas enregistré')
        // The tab found after the first click, not a second one.
        ->and($page->script("! window.kamiTab.closed && window.kamiTab.document.body.innerText.includes('Toujours pas enregistré')"))->toBeTrue();

    $page->assertNoJavaScriptErrors();

    // The banner at axe's strictest level, as the front's own pages.
    visit($page->script('window.kamiTab.location.href'))
        ->assertSee('Prévisualisation de modifications non enregistrées')
        ->assertNoAccessibilityIssues(3)
        ->assertNoJavaScriptErrors();
});
