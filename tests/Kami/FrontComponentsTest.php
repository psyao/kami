<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Facades\Kami;
use Workbench\App\Content\Article;

uses(RefreshDatabase::class);

beforeEach(function () {
    Kami::routes();
});

/** @param array<string, mixed> $values */
function localisedArticle(string $locale, array $values, ?Entry $entry = null): Entry
{
    return resolve(SaveEntry::class)->handle(Article::class, ['status' => 'published', 'author' => 'Ada Lovelace', ...$values], $entry, $locale);
}

it('links the Locales the Entry is visible in, each in its own language, the current one marked', function () {
    $entry = localisedArticle('fr', ['title' => 'Bonjour']);
    localisedArticle('en', ['title' => 'Hello'], $entry);

    $this->get('/articles/bonjour')->assertOk()->assertSeeInOrder([
        '<nav class="kami-locales" aria-label="Langues">',
        '<ul>',
        '<li><a href="http://localhost/articles/bonjour" hreflang="fr" lang="fr" aria-current="page">Français</a></li>',
        '<li><a href="http://localhost/en/articles/hello" hreflang="en" lang="en">English</a></li>',
        '</ul>',
        '</nav>',
    ], escape: false);

    $this->get('/en/articles/hello')->assertOk()
        ->assertSee('<nav class="kami-locales" aria-label="Languages">', escape: false)
        ->assertSee('<a href="http://localhost/en/articles/hello" hreflang="en" lang="en" aria-current="page">English</a>', escape: false);
});

it('renders no switcher for an Entry visible in one Locale, nor without a current Entry', function () {
    $entry = localisedArticle('fr', ['title' => 'Bonjour']);
    localisedArticle('en', ['title' => 'Hello', 'status' => 'draft'], $entry);

    $this->get('/articles/bonjour')->assertOk()->assertDontSee('kami-locales');

    expect(Blade::render('<x-kami::locales />'))->toBe('');
});

it('shows a moment in the Site\'s time zone and the page\'s language, its datetime in UTC', function () {
    $moment = CarbonImmutable::parse('2026-02-28 23:30', 'UTC');
    app()->setLocale('fr');

    expect(trim(Blade::render('<x-kami::date :value="$moment" />', ['moment' => $moment])))
        ->toBe('<time class="kami-date" datetime="2026-02-28T23:30:00Z">1 mars 2026</time>')
        ->and(trim(Blade::render('<x-kami::date :value="$moment" format="LLL" />', ['moment' => $moment])))
        ->toBe('<time class="kami-date" datetime="2026-02-28T23:30:00Z">1 mars 2026 00:30</time>');

    app()->setLocale('en');

    expect(trim(Blade::render('<x-kami::date :value="$moment" />', ['moment' => $moment])))
        ->toBe('<time class="kami-date" datetime="2026-02-28T23:30:00Z">March 1, 2026</time>');
});

it('shows a day as given, with no time zone to shift it', function () {
    config(['kami.sites.default.timezone' => 'America/New_York']);
    // As a Date Field reads it: midnight UTC.
    $day = CarbonImmutable::parse('2026-03-01', 'UTC');
    app()->setLocale('fr');

    expect(trim(Blade::render('<x-kami::date :value="$day" day />', ['day' => $day])))
        ->toBe('<time class="kami-date" datetime="2026-03-01">1 mars 2026</time>')
        ->and(trim(Blade::render('<x-kami::date :value="$day" />', ['day' => $day])))
        ->toContain('28 février 2026');
});

it('renders nothing for a null value', function () {
    expect(trim(Blade::render('<x-kami::date :value="null" />')))->toBe('');
});
