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

it('shows an Archive\'s pagination at axe\'s strictest level, light and dark', function (): void {
    $this->freezeTime();
    foreach (range(1, 11) as $day) {
        resolve(SaveEntry::class)->handle(Article::class, ['title' => "Article {$day}", 'status' => 'published', 'author' => 'Ada Lovelace', 'published_at' => now()->subDays($day)], locale: 'fr');
    }

    // The links carry the Site URL, not the test server's: the next page is visited by its path.
    $href = html_entity_decode((string) str((string) $this->get('/articles')->getContent())->match('/href="([^"]+)" rel="next"/'));
    $first = visit('/articles')->assertPresent('nav[aria-label="Pagination"] a[rel="next"]')->assertMissing('a[rel="prev"]');
    $older = visit(parse_url($href, PHP_URL_PATH) . '?' . parse_url($href, PHP_URL_QUERY))->assertSee('Article 11')->assertPresent('a[rel="prev"]');

    foreach ([$first, $older, visit('/articles')->inDarkMode()] as $page) {
        assertAxeClean($page)->assertNoJavaScriptErrors();
    }
});
