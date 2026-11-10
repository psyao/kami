<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Psyao\Kami\Content\Actions\SaveEntry;
use Workbench\App\Content\Article;

uses(RefreshDatabase::class);

it('shows the language switcher and a date at axe\'s strictest level, light and dark', function (): void {
    $save = resolve(SaveEntry::class);
    $entry = $save->handle(Article::class, ['title' => 'Bonjour', 'status' => 'published', 'author' => 'Ada Lovelace'], locale: 'fr');
    $save->handle(Article::class, ['title' => 'Hello', 'status' => 'published'], $entry, 'en');

    foreach ([visit('/articles/bonjour'), visit('/articles/bonjour')->inDarkMode()] as $page) {
        assertAxeClean($page->assertPresent('nav[aria-label="Langues"] a[hreflang="en"][lang="en"]')
            ->assertPresent('a[aria-current="page"][lang="fr"]')
            ->assertPresent('time[datetime]'))
            ->assertNoJavaScriptErrors();
    }
});
