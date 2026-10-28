<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Psyao\Kami\Admin\Internal\EntryResource\CreateEntry;
use Psyao\Kami\Admin\Internal\EntryResource\EditEntry;
use Psyao\Kami\Admin\Internal\EntryResource\ListEntries;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Facades\Kami;
use Symfony\Component\Finder\SplFileInfo;
use Workbench\App\Content\Article;
use Workbench\App\Content\ArticleWithDefaults;
use Workbench\App\Content\Page;
use Workbench\App\Models\User;

// A User committed here would outlive the test on MySQL, MariaDB and PostgreSQL and clash with the next one's.
uses(RefreshDatabase::class);

function administrator(): User
{
    $user = User::forceCreate(['name' => 'Ada Lovelace', 'email' => uniqid() . '@example.test', 'password' => 'secret']);
    DB::table('kami_role_user')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->getKey(), 'role' => 'administrator']);

    return $user;
}

/**
 * A page of the type's Entries, as the panel serves it at the type's URL.
 *
 * @param  class-string  $page
 * @param  array<string, mixed>  $parameters
 */
function entryPage(string $page, string $type = 'article', array $parameters = []): Testable
{
    Filament::setCurrentPanel('admin');
    Filament::setCurrentResourceConfigurationKey($type);

    return Livewire::test($page, $parameters);
}

/** A button under the form: Save draft, Publish, Unpublish. */
function formAction(string $name): TestAction
{
    return TestAction::make($name)->schemaComponent('form-actions', schema: 'content');
}

/** The type's only Entry, whatever its state, read in the default Locale. */
function onlyEntry(string $type = 'article'): Entry
{
    return Entry::query()->withUnpublished()->ofType($type)->sole();
}

beforeEach(function () {
    // Filament opens its panel to a User that is not a `FilamentUser` in the local environment only.
    config(['app.env' => 'local']);
    Kami::routes();
    $this->actingAs(administrator());
});

it('creates a Draft in the default Locale, hidden from the front', function () {
    entryPage(CreateEntry::class)
        ->fillForm(['title' => 'Bonjour', 'summary' => 'Un premier article.'])
        ->call('create')
        ->assertHasNoFormErrors();

    $entry = onlyEntry();

    expect($entry->title)->toBe('Bonjour')
        ->and($entry->slug)->toBe('bonjour')
        ->and($entry->status)->toBe('draft')
        ->and($entry->summary)->toBe('Un premier article.')
        ->and($entry->created_by)->toBe(auth()->id());

    $this->get('/articles/bonjour')->assertNotFound();
});

it('publishes now, and the front shows the Entry', function () {
    entryPage(CreateEntry::class)
        ->fillForm(['title' => 'Bonjour', 'author' => 'Ada Lovelace'])
        ->callAction(formAction('publish'))
        ->assertHasNoFormErrors();

    expect(onlyEntry()->published_at->timestamp)->toBeGreaterThanOrEqual(now()->subMinute()->timestamp);
    $this->get('/articles/bonjour')->assertOk()->assertSee('Bonjour');
});

it('schedules at a date to come, read in the Site\'s time zone, and the front waits for it', function () {
    $this->travelTo('2026-10-28 10:00:00');

    entryPage(CreateEntry::class)
        ->fillForm(['title' => 'Bonjour', 'author' => 'Ada Lovelace', 'published_at' => '2026-10-28 12:30:00'])
        ->callAction(formAction('publish'))
        ->assertHasNoFormErrors();

    // Zurich is UTC+1 on 28 October.
    expect(onlyEntry()->published_at->toDateTimeString())->toBe('2026-10-28 11:30:00');
    $this->get('/articles/bonjour')->assertNotFound();

    $this->travelTo('2026-10-28 11:30:00');
    $this->get('/articles/bonjour')->assertOk();
});

it('backdates, keeping the date given', function () {
    entryPage(CreateEntry::class)
        ->fillForm(['title' => 'Bonjour', 'author' => 'Ada Lovelace', 'published_at' => '2020-01-01 09:00:00'])
        ->callAction(formAction('publish'))
        ->assertHasNoFormErrors();

    expect(onlyEntry()->published_at->toDateTimeString())->toBe('2020-01-01 08:00:00');
    $this->get('/articles/bonjour')->assertOk();
});

it('unpublishes, keeping the publication date, and the front hides the Entry', function () {
    entryPage(CreateEntry::class)->fillForm(['title' => 'Bonjour', 'author' => 'Ada Lovelace'])->callAction(formAction('publish'));
    $publishedAt = onlyEntry()->published_at;

    entryPage(EditEntry::class, parameters: ['record' => onlyEntry()->id])
        ->callAction(formAction('unpublish'))
        ->assertHasNoFormErrors()
        ->assertActionVisible(formAction('publish'));

    expect(onlyEntry()->status)->toBe('draft')
        ->and(onlyEntry()->published_at?->equalTo($publishedAt))->toBeTrue();
    $this->get('/articles/bonjour')->assertNotFound();
});

it('edits a Draft, keeping it a Draft, and fills the form with its values', function () {
    entryPage(CreateEntry::class)->fillForm(['title' => 'Bonjour', 'summary' => 'Avant'])->call('create');

    entryPage(EditEntry::class, parameters: ['record' => onlyEntry()->id])
        ->assertSchemaStateSet(['title' => 'Bonjour', 'slug' => 'bonjour', 'summary' => 'Avant'])
        ->fillForm(['summary' => 'Après'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(onlyEntry()->summary)->toBe('Après')
        ->and(onlyEntry()->status)->toBe('draft');
});

it('checks required() on Publish only, with the error on the Field concerned', function () {
    entryPage(CreateEntry::class)
        ->fillForm(['title' => 'Bonjour'])
        ->callAction(formAction('publish'))
        ->assertHasFormErrors(['author'])
        ->assertHasNoFormErrors(['title', 'summary']);

    expect(Entry::query()->withUnpublished()->count())->toBe(0);

    entryPage(CreateEntry::class)
        ->fillForm(['title' => 'Bonjour'])
        ->call('create')
        ->assertHasNoFormErrors();
});

it('shows SaveEntry\'s other errors on their field', function () {
    entryPage(CreateEntry::class)
        ->fillForm(['title' => 'Bonjour', 'summary' => str_repeat('a', 201)])
        ->call('create')
        ->assertHasFormErrors(['summary']);
});

it('pre-fills the create form with the Fields\' defaults, which the Entry stores', function () {
    config(['kami.types' => [ArticleWithDefaults::class]]);

    entryPage(CreateEntry::class)
        ->assertSchemaStateSet(['summary' => 'To be written.', 'author' => 'The newsroom'])
        ->fillForm(['title' => 'Bonjour'])
        ->callAction(formAction('publish'))
        ->assertHasNoFormErrors();

    expect([onlyEntry()->summary, onlyEntry()->author])->toBe(['To be written.', 'The newsroom']);
});

it('lists the type\'s Entries with their state and publication date in the Site\'s time zone, searchable by title', function () {
    $this->travelTo('2026-10-28 10:00:00');
    $save = fn (array $values) => resolve(SaveEntry::class)->handle(Article::class, [...$values, 'author' => 'Ada Lovelace']);
    $draft = $save(['title' => 'Brouillon']);
    $scheduled = $save(['title' => 'Programmé', 'status' => 'published', 'published_at' => '2026-10-29 08:00:00']);
    $published = $save(['title' => 'Publié', 'status' => 'published']);
    $page = resolve(SaveEntry::class)->handle(Page::class, ['title' => 'Une page']);

    entryPage(ListEntries::class)
        ->assertCanSeeTableRecords([$draft, $scheduled, $published])
        ->assertCanNotSeeTableRecords([$page])
        ->assertTableColumnStateSet('state', 'Draft', $draft)
        ->assertTableColumnStateSet('state', 'Scheduled', $scheduled)
        ->assertTableColumnStateSet('state', 'Published', $published)
        ->assertTableColumnFormattedStateSet('published_at', 'Oct 29, 2026 09:00:00', $scheduled)
        ->searchTable('Progr')
        ->assertCanSeeTableRecords([$scheduled])
        ->assertCanNotSeeTableRecords([$draft, $published]);
});

it('lists Entries in a fixed number of queries, whatever their number', function (int $entries) {
    for ($i = 1; $i <= $entries; $i++) {
        resolve(SaveEntry::class)->handle(Article::class, ['title' => "Article {$i}"]);
    }

    DB::enableQueryLog();
    $this->get('/admin/articles')->assertOk()->assertSee('Article 1');

    expect(DB::getQueryLog())->toHaveCount(5);
})->with([1, 10]);

it('names each type in the navigation from kami-types, or from its handle', function () {
    app('translator')->addLines(['kami-types.article.plural' => 'Blog posts'], 'en');

    $this->get('/admin')
        ->assertOk()
        ->assertSee('Blog posts')
        ->assertSee('Pages')
        ->assertSee('Labels');
});

it('shows a User with no Role no Kami navigation item, and refuses every Kami page', function () {
    $entry = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour']);
    $this->actingAs(User::forceCreate(['name' => 'Nobody', 'email' => uniqid() . '@example.test', 'password' => 'secret']));

    $this->get('/admin')->assertOk()->assertDontSee('/admin/articles', escape: false);
    $this->get('/admin/articles')->assertForbidden();
    $this->get('/admin/articles/create')->assertForbidden();
    $this->get("/admin/articles/{$entry->id}/edit")->assertForbidden();
});

it('hides Publish and Unpublish from a User without publish, who saves Drafts only', function () {
    config(['kami.roles.writer' => ['kami.entries.article.view', 'kami.entries.article.create', 'kami.entries.article.update']]);
    $writer = User::forceCreate(['name' => 'Writer', 'email' => uniqid() . '@example.test', 'password' => 'secret']);
    DB::table('kami_role_user')->insert(['id' => (string) Str::ulid(), 'user_id' => $writer->getKey(), 'role' => 'writer']);
    $this->actingAs($writer);

    entryPage(CreateEntry::class)
        ->assertActionVisible(formAction('create'))
        ->assertActionDoesNotExist(formAction('publish'))
        ->fillForm(['title' => 'Bonjour'])
        ->call('create')
        ->assertHasNoFormErrors();

    entryPage(EditEntry::class, parameters: ['record' => onlyEntry()->id])
        ->assertActionDoesNotExist(formAction('publish'));
});

it('renders every Kami admin page in English and French with no raw translation key', function (string $locale) {
    config(['app.locale' => $locale]);
    $entries = collect(['article', 'page', 'label'])->mapWithKeys(fn (string $type) => [$type => resolve(SaveEntry::class)->handle($type, ['title' => "Une {$type}"])]);

    foreach ($entries as $type => $entry) {
        $slug = "{$type}s";

        foreach (["/admin/{$slug}", "/admin/{$slug}/create", "/admin/{$slug}/{$entry->id}/edit"] as $url) {
            $html = (string) $this->get($url)->assertOk()->getContent();

            expect($html)->not->toContain('kami::', $url)->not->toContain('kami-types.', $url);
        }
    }
})->with(['en', 'fr']);

it('throttles repeated failed sign-ins, as Filament does, refusing even the right password', function () {
    $user = User::forceCreate(['name' => 'Grace', 'email' => uniqid() . '@example.test', 'password' => Hash::make('right-password')]);
    $this->app['auth']->forgetGuards();
    Filament::setCurrentPanel('admin');
    $login = Livewire::test(Login::class);

    foreach (range(1, 5) as $attempt) {
        $login->fillForm(['email' => $user->email, 'password' => 'wrong'])->call('authenticate')->assertHasFormErrors(['email']);
    }

    $login->fillForm(['email' => $user->email, 'password' => 'right-password'])->call('authenticate')
        ->assertNotified();

    $this->assertGuest();
});

it('never writes a Kami model itself from Admin: every write goes through SaveEntry (ADR 0011)', function () {
    $writes = collect(File::allFiles(dirname(__DIR__, 2) . '/src/Admin'))
        ->filter(fn (SplFileInfo $file) => preg_match('/(?<!\$this)->(save|update|delete|push|forceFill|fill)\(|::(create|forceCreate|insert)\(|DB::/', $file->getContents()) === 1)
        ->map->getRelativePathname();

    expect($writes->values()->all())->toBe([]);
});
