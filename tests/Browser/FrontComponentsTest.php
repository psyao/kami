<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Menus\Actions\SaveMenuItem;
use Workbench\App\Content\Article;
use Workbench\App\Content\Page;

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

it('shows a Menu with its Trail at axe\'s strictest level, in two Locales, light and dark', function (): void {
    // The test server's host, so the current page is recognised: the Trail compares host and path, not the port.
    config(['kami.menus' => ['primary' => ['depth' => 2]], 'kami.sites.default.url' => 'http://127.0.0.1']);
    $save = resolve(SaveEntry::class);
    $about = $save->handle(Page::class, ['title' => 'À propos', 'status' => 'published'], locale: 'fr');
    $save->handle(Page::class, ['title' => 'About', 'status' => 'published'], $about, 'en');
    $team = $save->handle(Page::class, ['title' => 'Équipe', 'status' => 'published', 'parent' => $about->id], locale: 'fr');
    $save->handle(Page::class, ['title' => 'Team', 'status' => 'published'], $team, 'en');
    $menu = resolve(SaveMenuItem::class);
    $item = $menu->handle('primary', ['entry' => $about]);
    $menu->handle('primary', ['entry' => $team, 'parent' => $item->id]);
    $menu->handle('primary', ['archive' => 'article']);
    $menu->handle('primary', ['url' => 'https://example.com', 'label' => ['fr' => 'Ailleurs', 'en' => 'Elsewhere'], 'new_tab' => true]);

    foreach ([visit('/a-propos/equipe'), visit('/en/about/team')->inDarkMode()] as $page) {
        assertAxeClean($page->assertPresent('nav.kami-menu ul.kami-menu-list ul.kami-menu-list a[aria-current="page"]')
            ->assertPresent('nav.kami-menu > ul > li > a[aria-current="true"]')
            ->assertPresent('a[target="_blank"][rel="noopener"]'))
            ->assertNoJavaScriptErrors();
    }
});

it('shows a breadcrumb at axe\'s strictest level, in two Locales, light and dark', function (): void {
    $save = resolve(SaveEntry::class);
    $about = $save->handle(Page::class, ['title' => 'À propos', 'status' => 'published'], locale: 'fr');
    $save->handle(Page::class, ['title' => 'About', 'status' => 'published'], $about, 'en');
    $team = $save->handle(Page::class, ['title' => 'Équipe', 'status' => 'published', 'parent' => $about->id], locale: 'fr');
    $save->handle(Page::class, ['title' => 'Team', 'status' => 'published'], $team, 'en');

    foreach ([visit('/a-propos/equipe'), visit('/en/about/team')->inDarkMode()] as $page) {
        assertAxeClean($page->assertPresent('nav.kami-breadcrumb ol li a[aria-current="page"]'))->assertNoJavaScriptErrors();
    }
});
