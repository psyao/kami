<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Fields\Field as KamiField;
use Psyao\Kami\Content\Fields\Group;
use Psyao\Kami\Content\Fields\Repeater;
use Psyao\Kami\Content\Fields\Text;
use Psyao\Kami\Facades\Kami;
use Symfony\Component\Finder\SplFileInfo;
use Workbench\App\Content\Article;
use Workbench\App\Content\ArticleWithDefaults;
use Workbench\App\Content\Page;
use Workbench\App\Content\Project;
use Workbench\App\Models\User;

// A User committed here would outlive the test on MySQL, MariaDB and PostgreSQL and clash with the next one's.
uses(RefreshDatabase::class);

/** A Field type no plugin registers a component for. */
final class Rumour extends KamiField {}

final class Unregistered extends ContentType
{
    public function fields(): array
    {
        return [Rumour::make('whisper')];
    }
}

final class UnregisteredInside extends ContentType
{
    public function fields(): array
    {
        return [Group::make('place', [Rumour::make('whisper')])];
    }
}

/** On Project's handle and table: tests list it in place of Project. */
final class ProjectWithStructuredOptions extends ContentType
{
    public function handle(): string
    {
        return 'project';
    }

    public function fields(): array
    {
        return [
            Group::make('address', [Text::make('street'), Text::make('city')])->default(['city' => 'Vevey']),
            Repeater::make('links', [Text::make('label')])->translatable(),
        ];
    }
}

function userWithRole(string $role): User
{
    $user = User::forceCreate(['name' => 'Ada Lovelace', 'email' => uniqid() . '@example.test', 'password' => 'secret']);
    DB::table('kami_role_user')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->getKey(), 'role' => $role]);

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
    // Everything on Articles but publish: Drafts only (ADR 0019).
    config(['kami.roles.writer' => ['kami.entries.article.view', 'kami.entries.article.create', 'kami.entries.article.update']]);
    Kami::routes();
    $this->actingAs(userWithRole('administrator'));
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

it('fills the slug from the title on the create form, as SaveEntry would derive it, and follows the title while untouched', function () {
    $page = entryPage(CreateEntry::class)
        ->fillForm(['title' => 'Été à Genève'])
        ->assertSchemaStateSet(['slug' => 'ete-a-geneve'])
        ->fillForm(['title' => 'Hiver à Genève'])
        ->assertSchemaStateSet(['slug' => 'hiver-a-geneve']);

    $page->call('create')->assertHasNoFormErrors();

    expect(onlyEntry()->slug)->toBe('hiver-a-geneve');
});

it('asks the server on leaving the title only while there is a slug to fill', function () {
    entryPage(CreateEntry::class)->assertSeeHtml('wire:model.live.blur="data.title"')->fillForm(['title' => 'Bonjour'])->call('create');

    entryPage(EditEntry::class, parameters: ['record' => onlyEntry()->id])->assertDontSeeHtml('wire:model.live.blur="data.title"');
});

it('never overwrites a slug typed by hand, and resumes filling once it is emptied', function () {
    entryPage(CreateEntry::class)
        ->fillForm(['title' => 'Bonjour'])
        ->fillForm(['slug' => 'a-la-main'])
        ->fillForm(['title' => 'Bonsoir'])
        ->assertSchemaStateSet(['slug' => 'a-la-main'])
        ->fillForm(['slug' => ''])
        ->fillForm(['title' => 'Bonne nuit'])
        ->assertSchemaStateSet(['slug' => 'bonne-nuit']);
});

it('fills the slug on the screen of a Locale with no Translation yet, and saves what it showed', function () {
    entryPage(CreateEntry::class)->fillForm(['title' => 'Bonjour'])->call('create');

    entryPage(EditEntry::class, parameters: ['record' => onlyEntry()->id, 'locale' => 'en'])
        ->fillForm(['title' => 'Hello there', 'author' => 'Ada Lovelace'])
        ->assertSchemaStateSet(['slug' => 'hello-there'])
        ->callAction(formAction('publish'))
        ->assertHasNoFormErrors();

    $this->get('/en/articles/hello-there')->assertOk();
});

it('derives the slug in the screen\'s Locale', function () {
    entryPage(CreateEntry::class)->fillForm(['title' => 'Bonjour'])->call('create');

    // German transliterates ü to ue, the default Locale's rules to u.
    config(['kami.sites.default.locales' => ['fr', 'de']]);

    entryPage(EditEntry::class, parameters: ['record' => onlyEntry()->id, 'locale' => 'de'])
        ->fillForm(['title' => 'Grüße'])
        ->assertSchemaStateSet(['slug' => 'gruesse']);
});

it('fills the slug of a Draft saved without a title, which has none stored', function () {
    entryPage(CreateEntry::class)->fillForm(['summary' => 'Sans titre'])->call('create')->assertHasNoFormErrors();

    expect(onlyEntry()->slug)->toBeNull();

    entryPage(EditEntry::class, parameters: ['record' => onlyEntry()->id])
        ->fillForm(['title' => 'Enfin un titre'])
        ->assertSchemaStateSet(['slug' => 'enfin-un-titre']);
});

it('never changes a stored slug from the title, in any Locale, and warns that changing it moves the address', function () {
    entryPage(CreateEntry::class)->fillForm(['title' => 'Bonjour'])->call('create');
    entryPage(EditEntry::class, parameters: ['record' => onlyEntry()->id, 'locale' => 'en'])->fillForm(['title' => 'Hello'])->call('save');

    foreach (['fr' => 'bonjour', 'en' => 'hello'] as $locale => $slug) {
        entryPage(EditEntry::class, parameters: ['record' => onlyEntry()->id, ...($locale === 'fr' ? [] : ['locale' => $locale])])
            ->assertSee(__('kami::admin.fields.slug_moves'))
            ->fillForm(['title' => 'Autre titre'])
            ->assertSchemaStateSet(['slug' => $slug])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    expect(onlyEntry()->slug)->toBe('bonjour');
});

it('keeps the blank-slug help text until a slug is stored, then warns, on the same screen', function () {
    entryPage(CreateEntry::class)->assertSee(__('kami::admin.fields.slug_help'))->assertDontSee(__('kami::admin.fields.slug_moves'));

    entryPage(CreateEntry::class)->fillForm(['title' => 'Bonjour'])->call('create');

    entryPage(EditEntry::class, parameters: ['record' => onlyEntry()->id, 'locale' => 'en'])
        ->assertSee(__('kami::admin.fields.slug_help'))
        ->fillForm(['title' => 'Hello'])
        ->call('save')
        ->assertSee(__('kami::admin.fields.slug_moves'))
        ->assertDontSee(__('kami::admin.fields.slug_help'))
        ->fillForm(['title' => 'Hello again'])
        ->assertSchemaStateSet(['slug' => 'hello']);
});

it('fills and protects the slug of an untranslatable type the same way', function () {
    entryPage(CreateEntry::class, 'label')
        ->fillForm(['title' => 'À propos'])
        ->assertSchemaStateSet(['slug' => 'a-propos'])
        ->call('create')
        ->assertHasNoFormErrors();

    entryPage(EditEntry::class, 'label', ['record' => onlyEntry('label')->id])
        ->fillForm(['title' => 'Qui sommes-nous'])
        ->assertSchemaStateSet(['slug' => 'a-propos']);
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
        ->assertTableColumnStateSet('state_fr', 'Draft', $draft)
        ->assertTableColumnStateSet('state_fr', 'Scheduled', $scheduled)
        ->assertTableColumnStateSet('state_fr', 'Published', $published)
        ->assertTableColumnFormattedStateSet('published_at', 'Oct 29, 2026 09:00:00', $scheduled)
        ->searchTable('Progr')
        ->assertCanSeeTableRecords([$scheduled])
        ->assertCanNotSeeTableRecords([$draft, $published]);
});

it('shows each Locale\'s state in its own column, or one column for an untranslatable type', function () {
    $entry = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada Lovelace', 'status' => 'published']);
    $other = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Salut']);
    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Hello'], $entry, 'en');
    $label = resolve(SaveEntry::class)->handle('label', ['title' => 'Rouge', 'status' => 'published']);

    entryPage(ListEntries::class)
        ->assertTableColumnStateSet('state_fr', 'Published', $entry)
        ->assertTableColumnStateSet('state_en', 'Draft', $entry)
        ->assertTableColumnStateSet('state_fr', 'Draft', $other)
        ->assertTableColumnStateSet('state_en', 'Missing', $other);

    entryPage(ListEntries::class, 'label')
        ->assertTableColumnExists('state')
        ->assertTableColumnDoesNotExist('state_fr')
        ->assertTableColumnStateSet('state', 'Published', $label);
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
    $this->actingAs(userWithRole('writer'));

    entryPage(CreateEntry::class)
        ->assertActionVisible(formAction('create'))
        ->assertActionDoesNotExist(formAction('publish'))
        ->fillForm(['title' => 'Bonjour'])
        ->call('create')
        ->assertHasNoFormErrors();

    entryPage(EditEntry::class, parameters: ['record' => onlyEntry()->id])
        ->assertActionDoesNotExist(formAction('publish'));
});

it('keeps a Scheduled Translation and a Draft\'s publication date from a User without publish (ADR 0019)', function () {
    $scheduled = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada Lovelace', 'status' => 'published', 'published_at' => now()->addDay()]);
    $draft = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Brouillon']);
    $this->actingAs(userWithRole('writer'));

    $this->get("/admin/articles/{$scheduled->id}/edit")->assertForbidden();
    entryPage(EditEntry::class, parameters: ['record' => $draft->id])->assertFormFieldDisabled('published_at');
});

it('adds English from the French screen, each Locale publishing on its own', function () {
    entryPage(CreateEntry::class)->fillForm(['title' => 'Bonjour', 'author' => 'Ada Lovelace'])->callAction(formAction('publish'));

    entryPage(EditEntry::class, parameters: ['record' => onlyEntry()->id])
        ->assertActionHasLabel('locale_en', 'Add EN')
        ->assertActionHasUrl('locale_en', url('/admin/articles/' . onlyEntry()->id . '/edit/en'));

    entryPage(EditEntry::class, parameters: ['record' => onlyEntry()->id, 'locale' => 'en'])
        ->assertSchemaStateSet(['title' => null, 'author' => 'Ada Lovelace'])
        ->assertActionHasLabel('locale_fr', 'Edit FR')
        ->assertActionHasUrl('locale_fr', url('/admin/articles/' . onlyEntry()->id . '/edit'))
        ->fillForm(['title' => 'Hello'])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->get('/en/articles/hello')->assertNotFound();
    $this->get('/articles/bonjour')->assertOk();

    entryPage(EditEntry::class, parameters: ['record' => onlyEntry()->id, 'locale' => 'en'])
        ->assertSchemaStateSet(['title' => 'Hello'])
        ->callAction(formAction('publish'))
        ->assertHasNoFormErrors();

    $this->get('/en/articles/hello')->assertOk()->assertSee('Hello');

    entryPage(EditEntry::class, parameters: ['record' => onlyEntry()->id])->callAction(formAction('unpublish'));

    $this->get('/articles/bonjour')->assertNotFound();
    $this->get('/en/articles/hello')->assertOk();
});

it('saves a Shared Field once, whichever Locale\'s screen changes it', function () {
    $entry = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada Lovelace']);
    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Hello'], $entry, 'en');

    entryPage(EditEntry::class, parameters: ['record' => $entry->id, 'locale' => 'en'])
        ->assertSchemaStateSet(['title' => 'Hello', 'author' => 'Ada Lovelace'])
        ->fillForm(['author' => 'Grace Hopper'])
        ->call('save')
        ->assertHasNoFormErrors();

    entryPage(EditEntry::class, parameters: ['record' => $entry->id])
        ->assertSchemaStateSet(['title' => 'Bonjour', 'author' => 'Grace Hopper']);
    expect(DB::table('kami_type_article')->where('entry_id', $entry->id)->whereNotNull('author')->pluck('locale')->all())->toBe(['*']);
});

it('leaves a blank Translatable Field blank on its screen, not filled with the default Locale\'s value', function () {
    $entry = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'summary' => 'Un résumé.', 'author' => 'Ada Lovelace', 'status' => 'published']);
    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Hello'], $entry, 'en');

    entryPage(EditEntry::class, parameters: ['record' => $entry->id, 'locale' => 'en'])
        ->assertSchemaStateSet(['title' => 'Hello', 'summary' => null]);
});

it('keeps Shared Fields from a User without publish while another Translation is visible (ADR 0019)', function () {
    $entry = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada Lovelace', 'status' => 'published']);
    $this->actingAs(userWithRole('writer'));

    entryPage(EditEntry::class, parameters: ['record' => $entry->id, 'locale' => 'en'])
        ->assertFormFieldDisabled('author')
        ->fillForm(['title' => 'Hello', 'author' => 'Grace Hopper'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Entry::query()->inLocale('en')->withUnpublished()->sole()->title)->toBe('Hello')
        ->and(onlyEntry()->author)->toBe('Ada Lovelace');
});

it('keeps Shared Fields from a User without publish while another Translation is Scheduled (ADR 0019)', function () {
    $entry = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada Lovelace', 'status' => 'published', 'published_at' => now()->addDay()]);
    $this->actingAs(userWithRole('writer'));

    entryPage(EditEntry::class, parameters: ['record' => $entry->id, 'locale' => 'en'])->assertFormFieldDisabled('author');
});

it('refuses a Locale the Site does not declare', function () {
    $entry = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour']);

    $this->get("/admin/articles/{$entry->id}/edit/en")->assertOk();
    $this->get("/admin/articles/{$entry->id}/edit/de")->assertNotFound();
    $this->get("/admin/articles/{$entry->id}/edit/fr")->assertNotFound();
});

it('edits an untranslatable type\'s one Translation, with no other Locale to reach', function () {
    $label = resolve(SaveEntry::class)->handle('label', ['title' => 'Rouge', 'colour' => 'red']);

    entryPage(EditEntry::class, 'label', ['record' => $label->id])
        ->assertSchemaStateSet(['title' => 'Rouge', 'colour' => 'red'])
        ->assertActionDoesNotExist('locale_en')
        ->fillForm(['colour' => 'crimson'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(DB::table('kami_translations')->where('entry_id', $label->id)->pluck('locale')->all())->toBe(['*'])
        ->and(onlyEntry('label')->colour)->toBe('crimson');
    $this->get("/admin/labels/{$label->id}/edit/en")->assertNotFound();
});

it('links to the public URL of the Locale edited, only while it is visible', function () {
    $entry = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada Lovelace', 'status' => 'published']);
    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Hello'], $entry, 'en');

    entryPage(EditEntry::class, parameters: ['record' => $entry->id])
        ->assertActionVisible('view')
        ->assertActionHasUrl('view', 'http://localhost/articles/bonjour');
    entryPage(EditEntry::class, parameters: ['record' => $entry->id, 'locale' => 'en'])
        ->assertActionHidden('view');

    resolve(SaveEntry::class)->handle(Article::class, ['status' => 'published'], $entry, 'en');

    entryPage(EditEntry::class, parameters: ['record' => $entry->id, 'locale' => 'en'])
        ->assertActionHasUrl('view', 'http://localhost/en/articles/hello');
});

it('renders every Kami admin page in English and French with no raw translation key', function (string $locale) {
    config(['app.locale' => $locale]);
    $entries = collect(['article', 'page', 'label'])->mapWithKeys(fn (string $type) => [$type => resolve(SaveEntry::class)->handle($type, ['title' => "Une {$type}"])]);

    foreach ($entries as $type => $entry) {
        $slug = "{$type}s";

        foreach (["/admin/{$slug}", "/admin/{$slug}/create", "/admin/{$slug}/{$entry->id}/edit", ...($type === 'label' ? [] : ["/admin/{$slug}/{$entry->id}/edit/en"])] as $url) {
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

it('edits a Boolean with a toggle and reads a bool back', function () {
    entryPage(CreateEntry::class)
        ->fillForm(['title' => 'Bonjour', 'featured' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(onlyEntry()->featured)->toBeTrue();

    entryPage(EditEntry::class, parameters: ['record' => onlyEntry()->id])
        ->assertSchemaStateSet(['featured' => true])
        ->fillForm(['featured' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(onlyEntry()->featured)->toBeFalse();
});

it('stores false for a Boolean whose toggle the form never touches', function () {
    entryPage(CreateEntry::class)
        ->fillForm(['title' => 'Bonjour'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(onlyEntry()->featured)->toBeFalse();
});

it('edits a site\'s own Field type with the component it registered', function () {
    entryPage(CreateEntry::class)
        ->assertFormFieldExists('accent', fn (Field $field): bool => $field instanceof ColorPicker)
        ->fillForm(['title' => 'Bonjour', 'accent' => '#ABCDEF'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(onlyEntry()->accent)->toBe('#abcdef');

    entryPage(EditEntry::class, parameters: ['record' => onlyEntry()->id])->assertSchemaStateSet(['accent' => '#abcdef']);
});

it('edits each scalar Field with its usual input and reads the value back', function () {
    entryPage(CreateEntry::class)
        ->assertFormFieldExists('website', fn (Field $field): bool => $field instanceof TextInput && $field->getType() === 'url')
        ->assertFormFieldExists('contact', fn (Field $field): bool => $field instanceof TextInput && $field->getType() === 'email')
        ->assertFormFieldExists('pages', fn (Field $field): bool => $field instanceof TextInput && $field->getType() === 'number')
        ->assertFormFieldExists('rating', fn (Field $field): bool => $field instanceof TextInput && $field->getType() === 'number')
        ->assertFormFieldExists('starts_on', fn (Field $field): bool => $field instanceof DatePicker && ! $field->hasTime())
        ->assertFormFieldExists('starts_at', fn (Field $field): bool => $field instanceof DateTimePicker && $field->hasTime())
        ->assertFormFieldExists('kind', fn (Field $field): bool => $field instanceof Select && $field->getOptions() === ['news' => 'News', 'review' => 'Review'])
        ->fillForm(['title' => 'Bonjour', 'website' => 'https://example.test', 'contact' => 'ada@example.test', 'pages' => 12, 'rating' => '4.50', 'starts_on' => '2026-03-15', 'kind' => 'review'])
        ->call('create')
        ->assertHasNoFormErrors();

    $entry = onlyEntry();

    expect($entry->pages)->toBe(12)
        ->and($entry->rating)->toBe('4.50')
        ->and($entry->starts_on->toDateString())->toBe('2026-03-15')
        ->and($entry->kind)->toBe('review');

    entryPage(EditEntry::class, parameters: ['record' => $entry->id])
        ->assertSchemaStateSet(['pages' => 12, 'rating' => '4.50', 'starts_on' => '2026-03-15', 'kind' => 'review'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(onlyEntry()->starts_on->toDateString())->toBe('2026-03-15');
});

it('shows a DateTime in the Display time zone and stores it in UTC; a Date moves with no zone', function () {
    config(['kami.sites.default.timezone' => 'Europe/Zurich']);

    entryPage(CreateEntry::class)
        ->fillForm(['title' => 'Bonjour', 'starts_at' => '2026-03-15 10:30:00', 'starts_on' => '2026-03-15'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(onlyEntry()->starts_at->toDateTimeString())->toBe('2026-03-15 09:30:00')
        ->and(onlyEntry()->starts_on->toDateString())->toBe('2026-03-15');

    entryPage(EditEntry::class, parameters: ['record' => onlyEntry()->id])
        ->assertSet('data.starts_at', '2026-03-15 10:30:00')
        ->assertSet('data.starts_on', '2026-03-15')
        ->call('save')
        ->assertHasNoFormErrors();

    expect(onlyEntry()->starts_at->toDateTimeString())->toBe('2026-03-15 09:30:00')
        ->and(onlyEntry()->starts_on->toDateString())->toBe('2026-03-15');
});

it('refuses an invalid value on its input', function (string $field, mixed $value) {
    entryPage(CreateEntry::class)
        ->fillForm(['title' => 'Bonjour', $field => $value])
        ->call('create')
        ->assertHasFormErrors([$field]);
})->with([
    'Url' => ['website', 'nope'],
    'Email' => ['contact', 'nope'],
    'Select' => ['kind', 'gossip'],
]);

it('fails on the panel, naming the type, when a Field type has no registered component', function () {
    config(['kami.types' => [...config('kami.types'), Unregistered::class]]);

    $this->withoutExceptionHandling()->get('/admin');
})->throws(LogicException::class, 'No admin component for the Field type ' . Rumour::class);

it('arranges the form in the declared tabs and sections, titled from kami-types', function () {
    entryPage(CreateEntry::class, 'project')
        ->assertSee(['Details', 'Story', 'Highlight'])
        ->assertFormFieldExists('client')
        ->assertFormFieldExists('featured')
        ->assertFormFieldExists('story')
        ->assertFormFieldExists('launched_on');
});

it('shows a conditional Field as the editor fills its target', function () {
    entryPage(CreateEntry::class, 'project')
        ->assertFormFieldHidden('featured_note')
        ->assertFormFieldHidden('source')
        ->assertFormFieldHidden('story')
        ->fillForm(['featured' => true, 'kind' => 'essay', 'client' => 'Ada'])
        ->assertFormFieldVisible('featured_note')
        ->assertFormFieldVisible('source')
        ->assertFormFieldVisible('story')
        ->fillForm(['featured' => false, 'kind' => 'news', 'client' => ''])
        ->assertFormFieldHidden('featured_note')
        ->assertFormFieldHidden('source')
        ->assertFormFieldHidden('story');
});

it('clears a Field the form hid, as SaveEntry does for anyone', function () {
    entryPage(CreateEntry::class, 'project')
        ->fillForm(['title' => 'Kami', 'featured' => true, 'featured_note' => 'Why', 'client' => 'Ada', 'story' => 'Once'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(onlyEntry('project')->featured_note)->toBe('Why');

    entryPage(EditEntry::class, 'project', ['record' => onlyEntry('project')->id])
        ->assertFormFieldVisible('featured_note')
        ->fillForm(['featured' => false])
        ->assertFormFieldHidden('featured_note')
        ->call('save')
        ->assertHasNoFormErrors();

    expect(onlyEntry('project')->featured_note)->toBeNull()
        ->and(onlyEntry('project')->story)->toBe('Once');
});

it('adds, removes and reorders a Repeater\'s rows, and fills its Group', function () {
    $this->actingAs(userWithRole('administrator'));

    $entry = resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Kami', 'links' => [['label' => 'One', 'url' => 'https://one.test'], ['label' => 'Two', 'url' => 'https://two.test']], 'address' => ['street' => 'Rue du Lac', 'city' => 'Vevey']], locale: 'fr');

    $page = entryPage(EditEntry::class, 'project', ['record' => $entry->getKey()])
        ->assertSet('data.address.city', 'Vevey');
    $rows = array_keys($page->get('data.links'));

    $page->callAction(TestAction::make('reorder')->schemaComponent('links', 'form'), arguments: ['items' => array_reverse($rows)])
        ->callAction(TestAction::make('delete')->schemaComponent('links', 'form'), arguments: ['item' => $rows[0]])
        ->callAction(TestAction::make('add')->schemaComponent('links', 'form'));

    $added = array_key_last($page->get('data.links'));

    $page->set("data.links.{$added}.label", 'Three')->set("data.links.{$added}.url", 'https://three.test')
        ->set('data.address.city', 'Montreux')
        ->call('save')
        ->assertHasNoFormErrors();

    $saved = Entry::query()->withUnpublished()->findOrFail($entry->getKey());

    expect($saved->links)->toBe([['label' => 'Two', 'url' => 'https://two.test'], ['label' => 'Three', 'url' => 'https://three.test']])
        ->and($saved->address)->toBe(['street' => 'Rue du Lac', 'city' => 'Montreux']);
});

it('opens a new Entry with a Group\'s default on its sub-Fields', function () {
    config(['kami.types' => [ProjectWithStructuredOptions::class]]);
    $this->actingAs(userWithRole('administrator'));

    $page = entryPage(CreateEntry::class, 'project');

    expect($page->get('data.address.city'))->toBe('Vevey');

    $page->set('data.title', 'Kami')->call('create')->assertHasNoFormErrors();

    expect(Entry::query()->withUnpublished()->sole()->address)->toBe(['street' => null, 'city' => 'Vevey']);
});

it('fills a translatable Repeater with the Locale edited, and leaves a fallen-back one empty', function () {
    config(['kami.types' => [ProjectWithStructuredOptions::class]]);
    $this->actingAs(userWithRole('administrator'));
    $save = resolve(SaveEntry::class);
    $entry = $save->handle('project', ['title' => 'Kami', 'slug' => 'kami', 'status' => 'published', 'links' => [['label' => 'Site']]], locale: 'fr');
    $save->handle('project', ['title' => 'Kami', 'slug' => 'kami-en'], $entry, 'en');

    expect(array_values(entryPage(EditEntry::class, 'project', ['record' => $entry->id])->get('data.links')))->toBe([['label' => 'Site']])
        ->and(entryPage(EditEntry::class, 'project', ['record' => $entry->id, 'locale' => 'en'])->get('data.links'))->toBeEmpty();
});

it('opens a new Entry with no row and an empty Group, and stores neither', function () {
    $this->actingAs(userWithRole('administrator'));

    $page = entryPage(CreateEntry::class, 'project');

    expect($page->get('data.links'))->toBeEmpty();

    $page->set('data.title', 'Kami')->call('create')->assertHasNoFormErrors();

    expect(DB::table('kami_type_project')->where('locale', '*')->first())->links->toBeNull()->address->toBeNull();
});

it('puts SaveEntry\'s error on the input of the row concerned', function () {
    $this->actingAs(userWithRole('administrator'));

    $entry = resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Kami', 'links' => [['label' => 'One', 'url' => 'https://one.test'], ['label' => 'No address']]], locale: 'fr');
    $page = entryPage(EditEntry::class, 'project', ['record' => $entry->getKey()]);
    $rows = array_keys($page->get('data.links'));

    $page->callAction(formAction('publish'))->assertHasErrors(["data.links.{$rows[1]}.url"])->assertHasNoErrors(["data.links.{$rows[0]}.url"]);
});

it('shows a Repeater and a Group with their labels, sub-Fields included', function () {
    $this->actingAs(userWithRole('administrator'));

    entryPage(CreateEntry::class, 'project')->assertSee('Links')->assertSee('Address')->assertSee('City')->assertDontSee('kami-types');
});

it('stops the panel on a sub-Field type with no component', function () {
    config(['kami.types' => [UnregisteredInside::class]]);

    expect(fn () => Filament::getPanel('admin')->getPlugin('kami')->boot(Filament::getPanel('admin')))->toThrow(LogicException::class, Rumour::class);
});
