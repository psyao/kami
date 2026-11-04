<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Filament\Forms\Components\ColorPicker;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\GlobalSearch\Providers\Contracts\GlobalSearchProvider;
use Filament\GlobalSearch\Providers\DefaultGlobalSearchProvider;
use Filament\Livewire\GlobalSearch;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Psyao\Kami\Admin\Internal\KamiGlobalSearchProvider;
use Psyao\Kami\Admin\KamiPlugin;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Actions\TrashEntry;
use Psyao\Kami\Content\Entry;
use Workbench\App\Content\Article;
use Workbench\App\Content\Fields\Colour;
use Workbench\App\Content\Tag;

uses(RefreshDatabase::class);

/**
 * Each category's results as title, path and details.
 *
 * @return array<string, list<array{string, string, array<string, string>}>>
 */
function searchResults(Testable $search, string $query): array
{
    // A request boots the panel through its middleware, which `Livewire::test()` skips.
    Filament::bootCurrentPanel();
    $results = $search->set('search', $query)->instance()->getResults();

    return $results?->getCategories()->map(fn ($category): array => collect($category)->map(fn (GlobalSearchResult $result): array => [(string) $result->title, (string) parse_url($result->url, PHP_URL_PATH), $result->details])->all())->all() ?? [];
}

function searchedArticle(string $title, string $slug, ?Entry $entry = null, ?string $locale = null): Entry
{
    return resolve(SaveEntry::class)->handle(Article::class, ['title' => $title, 'slug' => $slug, 'author' => 'Ada'], $entry, $locale);
}

it('finds Translations by title or slug in every Locale, whatever the case, each leading to its own Locale\'s edit page', function (): void {
    $entry = searchedArticle('Été à Vevey', 'ete-a-vevey');
    searchedArticle('Summer in Vevey', 'summer-in-vevey', $entry, 'en');
    $tag = resolve(SaveEntry::class)->handle(Tag::class, ['title' => 'VEVEY']);
    searchedArticle('Lausanne', 'lausanne');

    $search = entriesPage(GlobalSearch::class, type: null);

    expect(searchResults($search, 'vEvEy'))->toBe([
        'Articles' => [
            ['Été à Vevey', "/admin/articles/{$entry->id}/edit", ['Locale' => 'FR']],
            ['Summer in Vevey', "/admin/articles/{$entry->id}/edit/en", ['Locale' => 'EN']],
        ],
        'Tags' => [
            ['VEVEY', "/admin/tags/{$tag->id}/edit", []],
        ],
    ])
        ->and(searchResults($search, 'SUMMER-IN'))->toBe([
            'Articles' => [['Summer in Vevey', "/admin/articles/{$entry->id}/edit/en", ['Locale' => 'EN']]],
        ]);
});

it('skips the types the User may not list and leaves the Trash out', function (): void {
    searchedArticle('Vevey', 'vevey');
    $tag = resolve(SaveEntry::class)->handle(Tag::class, ['title' => 'Vevey']);
    resolve(TrashEntry::class)->handle(resolve(SaveEntry::class)->handle(Tag::class, ['title' => 'Vevey (old)']));

    expect(searchResults(entriesPage(GlobalSearch::class, ['kami.entries.tag.view'], type: null), 'vevey'))->toBe([
        'Tags' => [['Vevey', "/admin/tags/{$tag->id}/edit", []]],
    ]);
});

it('sets its provider on Kami\'s panel only, after the host\'s own choice', function (): void {
    $booted = fn (Panel $panel): ?GlobalSearchProvider => tap($panel)->boot()->getGlobalSearchProvider();
    $own = new class extends DefaultGlobalSearchProvider {};
    // The Workbench's Fields need its components, as on its own panel.
    $kami = fn (): KamiPlugin => KamiPlugin::make()->fieldComponents([Colour::class => fn (Colour $field): ColorPicker => ColorPicker::make($field->handle)]);

    expect($booted(Panel::make()->id('kami')->plugin($kami())))->toBeInstanceOf(KamiGlobalSearchProvider::class)
        ->and($booted(Panel::make()->id('other'))::class)->toBe(DefaultGlobalSearchProvider::class)
        ->and(resolve(DefaultGlobalSearchProvider::class)::class)->toBe(DefaultGlobalSearchProvider::class)
        ->and($booted(Panel::make()->id('own')->plugin($kami())->globalSearch($own::class)))->toBeInstanceOf($own::class)
        ->and($booted(Panel::make()->id('off')->plugin($kami())->globalSearch(false)))->toBeNull();
});

it('shows the search field once to a User who may list a type, though Filament shows none for Resources registered per configuration', function (array $permissions, int $fields): void {
    signInWith($permissions);

    $page = (string) $this->get('/admin')->assertOk()->getContent();

    expect(substr_count($page, 'fi-global-search-field'))->toBe($fields);
})->with([
    'a type' => [['kami.entries.tag.view'], 1],
    'none' => [[], 0],
]);

it('leaves out a Translation in a Locale the Site no longer has, which has no screen', function (): void {
    $entry = searchedArticle('Vevey', 'vevey');
    searchedArticle('Vevey in English', 'vevey-in-english', $entry, 'en');
    config(['kami.sites.default.locales' => ['fr']]);

    expect(searchResults(entriesPage(GlobalSearch::class, type: null), 'vevey'))->toBe([
        'Articles' => [['Vevey', "/admin/articles/{$entry->id}/edit", ['Locale' => 'FR']]],
    ]);
});
