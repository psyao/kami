<?php

declare(strict_types=1);

use Filament\Forms\Components\ColorPicker;
use Filament\Panel;
use Filament\Widgets\AccountWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Psyao\Kami\Admin\Internal\Widgets\NeedsAttention;
use Psyao\Kami\Admin\Internal\Widgets\RecentlyEdited;
use Psyao\Kami\Admin\Internal\Widgets\Scheduled;
use Psyao\Kami\Admin\KamiPlugin;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Actions\TrashEntry;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Internal\Translation;
use Workbench\App\Content\Article;
use Workbench\App\Content\Fields\Colour;
use Workbench\App\Content\Page;
use Workbench\App\Content\Tag;

uses(RefreshDatabase::class);

/** An Article saved a minute after the last one, so "last edited" has one order on every engine. */
function editedArticle(string $title, array $data = [], ?Entry $entry = null, ?string $locale = null): Entry
{
    test()->travel(1)->minutes();

    return resolve(SaveEntry::class)->handle(Article::class, ['title' => $title, 'author' => 'Ada', ...$data], $entry, $locale);
}

function translationTitled(string $title): Translation
{
    return Translation::query()->where('title', $title)->sole();
}

/** A doctor result where `kami:doctor` writes it, in a storage of the test's own: parallel processes share the skeleton's. */
function doctorResult(?string $json): void
{
    app()->useStoragePath($storage = temporaryPath());
    File::ensureDirectoryExists("{$storage}/framework/kami");

    if ($json !== null) {
        File::put("{$storage}/framework/kami/doctor.json", $json);
    }
}

it('merges its three widgets into the host\'s dashboard, keeping the host\'s own', function () {
    $kami = KamiPlugin::make()->fieldComponents([Colour::class => fn (Colour $field): ColorPicker => ColorPicker::make($field->handle)]);

    expect(Panel::make()->id('host')->widgets([AccountWidget::class])->plugin($kami)->getWidgets())
        ->toContain(AccountWidget::class, RecentlyEdited::class, Scheduled::class, NeedsAttention::class);
});

it('shows each widget only to a User holding its Permission', function (array $permissions, array $shown) {
    signInWith($permissions);
    $page = $this->get('/admin')->assertOk();

    foreach ([RecentlyEdited::class, Scheduled::class, NeedsAttention::class] as $widget) {
        in_array($widget, $shown, true) ? $page->assertSeeLivewire($widget) : $page->assertDontSeeLivewire($widget);
    }
})->with([
    'every Permission' => [['kami.*'], [RecentlyEdited::class, Scheduled::class, NeedsAttention::class]],
    'a type' => [['kami.entries.tag.view'], [RecentlyEdited::class, Scheduled::class]],
    'health' => [['kami.health.view'], [NeedsAttention::class]],
    'none' => [[], []],
]);

it('lists the last 10 Entries the User may view, Drafts included and the Trash left out, each leading to its edit page', function () {
    $articles = collect(range(1, 11))->map(fn (int $i): Entry => editedArticle("Article {$i}"));
    resolve(TrashEntry::class)->handle(editedArticle('Trashed'));
    test()->travel(1)->minutes();
    resolve(SaveEntry::class)->handle(Page::class, ['title' => 'A page']);
    $last = $articles->last();

    entriesPage(RecentlyEdited::class, ['kami.entries.article.view'], type: null)
        ->assertCanSeeTableRecords($articles->slice(1)->reverse(), inOrder: true)
        ->assertCanNotSeeTableRecords([$articles->first()])
        ->assertTableColumnStateSet('title', 'Article 11', $last)
        ->assertTableColumnStateSet('type', 'Article', $last)
        ->assertSeeHtml("/admin/articles/{$last->id}/edit\"");
});

// Filament fills a closure parameter by type only for the record's exact class: a Term is not an Entry to it (#383).
it('titles a Term among the recently edited Entries', function () {
    $tag = resolve(SaveEntry::class)->handle(Tag::class, ['title' => 'Vevey']);

    entriesPage(RecentlyEdited::class, ['kami.entries.tag.view'], type: null)
        ->assertCanSeeTableRecords([$tag])
        ->assertTableColumnStateSet('title', 'Vevey', $tag)
        ->assertTableColumnStateSet('type', 'Tag', $tag);
});

it('lists the Translations to come, soonest first, dated in the Site\'s time zone and leading to their Locale', function () {
    config(['kami.sites.default.timezone' => 'Europe/Zurich']);
    $this->travelTo('2026-03-01 10:00:00');
    $scheduled = ['status' => 'published', 'published_at' => '2026-03-03 12:00:00'];
    $entry = editedArticle('Plus tard', $scheduled);
    editedArticle('Sooner', [...$scheduled, 'published_at' => '2026-03-02 08:00:00'], $entry, 'en');
    editedArticle('Publié', ['status' => 'published']);
    editedArticle('Brouillon daté', ['published_at' => '2026-03-02 08:00:00']);
    resolve(TrashEntry::class)->handle(editedArticle('Jeté', $scheduled));
    resolve(SaveEntry::class)->handle(Page::class, ['title' => 'A page', ...$scheduled]);

    entriesPage(Scheduled::class, ['kami.entries.article.view'], type: null)
        ->assertCanSeeTableRecords([translationTitled('Sooner'), translationTitled('Plus tard')], inOrder: true)
        ->assertCanNotSeeTableRecords([translationTitled('Publié'), translationTitled('Brouillon daté'), translationTitled('Jeté'), translationTitled('A page')])
        ->assertTableColumnStateSet('locale', 'EN', translationTitled('Sooner'))
        ->assertTableColumnFormattedStateSet('published_at', 'Mar 2, 2026 09:00:00', translationTitled('Sooner'))
        ->assertSeeHtml("/admin/articles/{$entry->id}/edit/en\"")
        ->assertSeeHtml("/admin/articles/{$entry->id}/edit\"");
});

it('leaves out a Scheduled Translation in a Locale the Site no longer has, which has no screen', function () {
    editedArticle('Hello later', ['status' => 'published', 'published_at' => now()->addDay()], editedArticle('Plus tard', ['status' => 'published', 'published_at' => now()->addDay()]), 'en');
    config(['kami.sites.default.locales' => ['fr']]);

    entriesPage(Scheduled::class, ['kami.entries.article.view'], type: null)
        ->assertCanSeeTableRecords([translationTitled('Plus tard')])
        ->assertCanNotSeeTableRecords([translationTitled('Hello later')]);
});

// An Entry loads its Translations and its type's row with it: 3 queries for Entries, 4 from the Translations.
it('reads each list in a fixed number of queries, whatever its size', function (string $widget, int $queries, int $entries) {
    for ($i = 1; $i <= $entries; $i++) {
        editedArticle("Article {$i}", ['status' => 'published', 'published_at' => now()->addDay()]);
    }
    $page = entriesPage($widget, ['kami.entries.article.view'], type: null);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $page->call('$refresh');

    expect(DB::getQueryLog())->toHaveCount($queries);
})->with([[RecentlyEdited::class, 3], [Scheduled::class, 4]])->with([1, 10]);

it('shows the last doctor result, dated in the Site\'s time zone, with each problem\'s fix', function () {
    config(['kami.sites.default.timezone' => 'Europe/Zurich']);
    doctorResult(json_encode(['checked_at' => '2026-03-01T10:00:00+00:00', 'problems' => [
        ['severity' => 'error', 'message' => 'The PHP extension intl is not loaded.', 'fix' => 'enable intl.', 'guide' => 'installation.md#requirements'],
        ['severity' => 'fatal', 'message' => 'Of a severity Kami does not know.', 'fix' => 'none.', 'guide' => ''],
        ['severity' => 'warning', 'message' => 'With no fix.'],
        ['severity' => 'warning', 'message' => 'Neither gd nor imagick is loaded.', 'fix' => 'enable gd or imagick.', 'guide' => 'installation.md#requirements'],
    ]]));

    entriesPage(NeedsAttention::class, ['kami.health.view'], type: null)
        ->assertSee('The PHP extension intl is not loaded.')
        ->assertSee('enable gd or imagick.')
        ->assertDontSee('Of a severity Kami does not know.')
        ->assertDontSee('With no fix.')
        ->assertSee(__('kami::admin.widgets.attention.checked_at', ['date' => 'Mar 1, 2026 11:00:00']));
});

it('names the command while no readable result exists, and says when the last run found nothing', function (?string $json, string $heading) {
    doctorResult($json);

    entriesPage(NeedsAttention::class, ['kami.health.view'], type: null)
        ->assertSee(__($heading))
        ->assertSee('php artisan kami:doctor');
})->with([
    'no file' => [null, 'kami::admin.widgets.attention.never'],
    'a broken file' => ['{"checked_at":', 'kami::admin.widgets.attention.never'],
    'a broken date' => ['{"checked_at":"yesterday-ish","problems":[]}', 'kami::admin.widgets.attention.never'],
    'no problem' => ['{"checked_at":"2026-03-01T10:00:00+00:00","problems":[]}', 'kami::admin.widgets.attention.none'],
]);

it('renders its widgets in English and French with no raw translation key', function (string $locale) {
    config(['app.locale' => $locale]);
    doctorResult(null);

    foreach ([RecentlyEdited::class, Scheduled::class, NeedsAttention::class] as $widget) {
        expect(entriesPage($widget, type: null)->html())->not->toContain('kami::');
    }
})->with(['en', 'fr']);
