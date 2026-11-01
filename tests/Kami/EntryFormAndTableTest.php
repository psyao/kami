<?php

declare(strict_types=1);

use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text as TextComponent;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Psyao\Kami\Admin\EntryForm;
use Psyao\Kami\Admin\EntryTable;
use Psyao\Kami\Admin\Internal\EntryResource;
use Psyao\Kami\Admin\Internal\EntryResource\CreateEntry;
use Psyao\Kami\Admin\Internal\EntryResource\EditEntry;
use Psyao\Kami\Admin\Internal\EntryResource\ListEntries;
use Psyao\Kami\Admin\KamiPlugin;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Fields\Group;
use Psyao\Kami\Content\Fields\Repeater;
use Psyao\Kami\Content\Fields\Text;
use Psyao\Kami\Content\SiteSettings;
use Psyao\Kami\Content\Tab;
use Psyao\Kami\Facades\Kami;
use Workbench\App\Content\Article;
use Workbench\App\Content\Page;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

final class Commission extends ContentType
{
    public function handle(): string
    {
        return 'project';
    }

    public function fields(): array
    {
        return [
            Text::make('client'),
            Group::make('address', [Text::make('city')]),
            Repeater::make('links', [Text::make('url')]),
        ];
    }
}

final class RearrangedCommissionForm extends EntryForm
{
    public function components(): array
    {
        return [
            Section::make('Main')->schema([$this->field('title'), $this->field('slug')]),
            TextComponent::make('Ask the client first.'),
            $this->field('client')->helperText('Chained by the site'),
            $this->field('address'),
            $this->field('links'),
            $this->field('published_at'),
            Actions::make([Action::make('inline')]),
        ];
    }

    public function actions(): array
    {
        return [Action::make('archive')];
    }
}

final class ChainedCommissionForm extends EntryForm
{
    public function components(): array
    {
        $this->field('address.city')->helperText('Chained in place');

        return parent::components();
    }
}

final class FormInventingAField extends EntryForm
{
    public function components(): array
    {
        return [...parent::components(), TextInput::make('invented')];
    }
}

final class FormLeavingOutAField extends EntryForm
{
    public function components(): array
    {
        return array_values(array_filter(parent::components(), fn (Component $component): bool => $component !== $this->field('client')));
    }
}

final class FormLeavingOutTheSlug extends EntryForm
{
    public function components(): array
    {
        return array_values(array_filter(parent::components(), fn (Component $component): bool => $component !== $this->field('slug')));
    }
}

final class FormReadingItsRecord extends EntryForm
{
    public function components(): array
    {
        return [
            Section::make('Main')->schema(fn (?Entry $record): array => $record instanceof Entry ? [$this->field('title'), $this->field('slug')] : [TextInput::make('invented')]),
            $this->field('client'),
            $this->field('address'),
            $this->field('links'),
            $this->field('published_at'),
        ];
    }
}

final class FormHidingAField extends EntryForm
{
    public function components(): array
    {
        $this->field('client')->hidden();

        return parent::components();
    }
}

final class TabbedCommission extends ContentType
{
    public function handle(): string
    {
        return 'project';
    }

    public function fields(): array
    {
        return [Tab::make('details', [Text::make('client')]), Tab::make('more', [Text::make('source')])];
    }
}

final class FormLeavingOutATab extends EntryForm
{
    public function components(): array
    {
        return [$this->field('title'), $this->field('slug'), $this->field('client'), $this->field('published_at')];
    }
}

final class SettingsForm extends EntryForm
{
    public function components(): array
    {
        return [$this->field('site_name')];
    }

    public function actions(): array
    {
        return [Action::make('purge')];
    }
}

final class FormNamingNoSuchField extends EntryForm
{
    public function components(): array
    {
        return [$this->field('nope')];
    }
}

/** A site's own date input, without the generated one's `disabled`. */
final class OwnDateInputForm extends EntryForm
{
    public function components(): array
    {
        return array_map(fn (Component $component): Component => $component === $this->field('published_at') ? TextInput::make('published_at') : $component, parent::components());
    }
}

/** Every input a Permission keeps from a User, opened again. */
final class ReopenedForm extends EntryForm
{
    public function components(): array
    {
        foreach (EntryResource::type() instanceof Article ? ['published_at', 'author'] : ['parent'] as $handle) {
            $this->field($handle)->disabled(false);
        }

        return parent::components();
    }
}

final class CommissionTable extends EntryTable
{
    public function columns(): array
    {
        return [TextColumn::make('title'), TextColumn::make('client')];
    }

    public function filters(): array
    {
        return [Filter::make('recent')];
    }

    public function recordActions(): array
    {
        return [Action::make('archive')];
    }
}

final class PageTable extends EntryTable
{
    public function columns(): array
    {
        return [TextColumn::make('title')->description('Slim')];
    }
}

function projectPage(string $page, array $parameters = []): Testable
{
    Filament::setCurrentPanel('admin');
    Filament::setCurrentResourceConfigurationKey('project');

    return Livewire::test($page, $parameters);
}

function asWriter(): void
{
    $writer = User::forceCreate(['name' => 'Grace Hopper', 'email' => 'grace@example.test', 'password' => 'secret']);
    DB::table('kami_role_user')->insert(['id' => (string) Str::ulid(), 'user_id' => $writer->getKey(), 'role' => 'writer']);
    test()->actingAs($writer);
}

function typePage(string $type, string $page, array $parameters = []): Testable
{
    Filament::setCurrentPanel('admin');
    Filament::setCurrentResourceConfigurationKey($type);

    return Livewire::test($page, $parameters);
}

function kamiPlugin(): KamiPlugin
{
    $plugin = Filament::getPanel('admin')->getPlugin('kami');
    assert($plugin instanceof KamiPlugin);

    return $plugin;
}

beforeEach(function () {
    config(['app.env' => 'local', 'kami.types' => [Commission::class]]);
    Kami::routes();
    $user = User::forceCreate(['name' => 'Ada Lovelace', 'email' => 'ada@example.test', 'password' => 'secret']);
    DB::table('kami_role_user')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->getKey(), 'role' => 'administrator']);
    $this->actingAs($user);
});

describe('EntryForm', function () {
    it('rearranges the generated components, adds what holds no value, and stores through the declaration', function () {
        kamiPlugin()->forms([Commission::class => RearrangedCommissionForm::class]);

        projectPage(CreateEntry::class)
            ->assertSee('Ask the client first.')
            ->assertSee('Chained by the site')
            ->set('data.title', 'Refonte')
            ->set('data.client', 'Acme')
            ->set('data.address.city', 'Vevey')
            ->call('create')
            ->assertHasNoFormErrors();

        $entry = Entry::query()->withUnpublished()->ofType('project')->sole();

        expect($entry->client)->toBe('Acme')->and($entry->address['city'])->toBe('Vevey');
    });

    it('places header actions on the edit page', function () {
        kamiPlugin()->forms([Commission::class => RearrangedCommissionForm::class]);
        $entry = resolve(SaveEntry::class)->handle(Commission::class, ['title' => 'Refonte'], locale: 'fr');

        projectPage(EditEntry::class, ['record' => $entry->id])->assertActionExists('archive');
    });

    it('chains a call onto a generated component left in place, a sub-Field included', function () {
        kamiPlugin()->forms([Commission::class => ChainedCommissionForm::class]);

        projectPage(CreateEntry::class)->assertSee('Chained in place');
    });

    it('builds a form whose closure reads the record of the page', function () {
        kamiPlugin()->forms([Commission::class => FormReadingItsRecord::class]);
        $entry = resolve(SaveEntry::class)->handle(Commission::class, ['title' => 'Refonte'], locale: 'fr');

        projectPage(EditEntry::class, ['record' => $entry->id])->assertFormFieldExists('title');
    });

    it('keeps the stored value of a Field a form hides', function () {
        kamiPlugin()->forms([Commission::class => FormHidingAField::class]);
        $entry = resolve(SaveEntry::class)->handle(Commission::class, ['title' => 'Refonte', 'client' => 'Acme'], locale: 'fr');

        projectPage(EditEntry::class, ['record' => $entry->id])->assertFormFieldHidden('client')->call('save')->assertHasNoFormErrors();

        expect(Entry::query()->withUnpublished()->findOrFail($entry->id)->client)->toBe('Acme');
    });

    it('finds a Field left out of a Tab', function () {
        config(['kami.types' => [TabbedCommission::class]]);
        kamiPlugin()->forms([TabbedCommission::class => FormLeavingOutATab::class]);

        projectPage(CreateEntry::class);
    })->throws('FormLeavingOutATab leaves out [source]');

    it('gives a Global Set its own form, with no title to require', function () {
        config(['kami.types' => [SiteSettings::class]]);
        kamiPlugin()->forms([SiteSettings::class => SettingsForm::class]);
        resolve(SaveEntry::class)->handle(SiteSettings::class, [], locale: 'fr');
        Filament::setCurrentPanel('admin');
        Filament::setCurrentResourceConfigurationKey('site_settings');

        Livewire::test(EditEntry::class)->assertFormFieldExists('site_name')->assertActionExists('purge');
    });

    it('refuses a field component with no declared Field', function () {
        kamiPlugin()->forms([Commission::class => FormInventingAField::class]);

        projectPage(CreateEntry::class);
    })->throws('FormInventingAField adds [invented]');

    it('refuses a declared Field left out', function () {
        kamiPlugin()->forms([Commission::class => FormLeavingOutAField::class]);

        projectPage(CreateEntry::class);
    })->throws('FormLeavingOutAField leaves out [client]');

    it('refuses a generated input left out, as a Field', function () {
        kamiPlugin()->forms([Commission::class => FormLeavingOutTheSlug::class]);

        projectPage(CreateEntry::class);
    })->throws('FormLeavingOutTheSlug leaves out [slug]');

    it('refuses a handle the generated form does not have', function () {
        kamiPlugin()->forms([Commission::class => FormNamingNoSuchField::class]);

        projectPage(CreateEntry::class);
    })->throws('[nope]');
});

describe('EntryTable', function () {
    it('chooses its columns, filters and row actions, and keeps the generated ones by default', function () {
        $entry = resolve(SaveEntry::class)->handle(Commission::class, ['title' => 'Refonte', 'client' => 'Acme'], locale: 'fr');

        projectPage(ListEntries::class)
            ->assertTableColumnExists('title')
            ->assertTableColumnDoesNotExist('client')
            ->assertActionExists(TestAction::make('edit')->table($entry));

        kamiPlugin()->tables([Commission::class => CommissionTable::class]);

        projectPage(ListEntries::class)
            ->assertTableColumnExists('client')
            ->assertTableColumnDoesNotExist('published_at')
            ->assertTableFilterExists('recent')
            ->assertActionExists(TestAction::make('archive')->table($entry))
            ->assertActionDoesNotExist(TestAction::make('edit')->table($entry))
            ->assertSee('Acme');
    });

    it('stays at the level and in the manual order whatever a site chooses', function () {
        config(['kami.types' => [Page::class]]);
        kamiPlugin()->tables([Page::class => PageTable::class]);
        $save = resolve(SaveEntry::class);
        $root = $save->handle(Page::class, ['title' => 'Root'], locale: 'fr');
        $child = $save->handle(Page::class, ['title' => 'Child', 'parent' => $root->id], locale: 'fr');

        Filament::setCurrentPanel('admin');
        Filament::setCurrentResourceConfigurationKey('page');

        Livewire::test(ListEntries::class)
            ->assertCanSeeTableRecords([$root])
            ->assertCanNotSeeTableRecords([$child])
            ->assertSee('Slim')
            ->call('toggleTableReordering')
            ->assertSet('isTableReordering', true);
    });
});

describe('what a Permission keeps from a User, whatever the form', function () {
    beforeEach(function () {
        config(['kami.types' => [Article::class, Page::class], 'kami.roles.writer' => [
            ...collect(['article', 'page'])->crossJoin(['view', 'create', 'update'])->map(fn (array $pair): string => "kami.entries.{$pair[0]}.{$pair[1]}"),
        ]]);
    });

    it('keeps a Draft\'s publication date from a User without publish', function (?string $form) {
        kamiPlugin()->forms(array_filter([Article::class => $form]));
        $draft = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour']);
        asWriter();

        typePage('article', EditEntry::class, ['record' => $draft->id])
            ->set('data.title', 'Salut')
            ->set('data.published_at', '2020-01-01 09:00:00')
            ->call('save')
            ->assertHasNoFormErrors();

        $saved = Entry::query()->withUnpublished()->findOrFail($draft->id);

        expect($saved->title)->toBe('Salut')->and($saved->published_at)->toBeNull();
    })->with(['generated' => [null], 'own input' => [OwnDateInputForm::class], 'reopened' => [ReopenedForm::class]]);

    it('keeps the date of an Entry being created from a User without publish', function (?string $form) {
        kamiPlugin()->forms(array_filter([Article::class => $form]));
        asWriter();

        typePage('article', CreateEntry::class)
            ->set('data.title', 'Bonjour')
            ->set('data.published_at', '2020-01-01 09:00:00')
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Entry::query()->withUnpublished()->ofType('article')->sole()->published_at)->toBeNull();
    })->with(['generated' => [null], 'own input' => [OwnDateInputForm::class], 'reopened' => [ReopenedForm::class]]);

    it('saves the date of a User holding publish, through a site\'s own input', function () {
        kamiPlugin()->forms([Article::class => OwnDateInputForm::class]);
        $draft = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour']);

        typePage('article', EditEntry::class, ['record' => $draft->id])
            ->set('data.published_at', '2020-01-01 09:00:00')
            ->call('save')
            ->assertHasNoFormErrors();

        expect(Entry::query()->withUnpublished()->findOrFail($draft->id)->published_at?->toDateTimeString())->toBe('2020-01-01 09:00:00');
    });

    it('keeps a Translation published while the page is open from a User without publish', function () {
        kamiPlugin()->forms([Article::class => ReopenedForm::class]);
        $draft = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada Lovelace']);
        asWriter();
        $page = typePage('article', EditEntry::class, ['record' => $draft->id])->set('data.title', 'Salut')->set('data.published_at', '2020-01-01 09:00:00');
        resolve(SaveEntry::class)->handle(Article::class, ['status' => 'published', 'published_at' => '2026-01-01 00:00:00'], $draft);

        $page->call('save')->assertForbidden();

        $saved = Entry::query()->findOrFail($draft->id);

        expect($saved->title)->toBe('Bonjour')->and($saved->published_at?->toDateTimeString())->toBe('2026-01-01 00:00:00');
    });

    it('keeps a Shared Field from a User without publish while another Translation is published', function () {
        kamiPlugin()->forms([Article::class => ReopenedForm::class]);
        $entry = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada Lovelace', 'status' => 'published']);
        asWriter();

        typePage('article', EditEntry::class, ['record' => $entry->id, 'locale' => 'en'])
            ->set('data.title', 'Hello')
            ->set('data.author', 'Grace Hopper')
            ->call('save')
            ->assertHasNoFormErrors();

        $saved = Entry::query()->inLocale('en')->withUnpublished()->findOrFail($entry->id);

        expect($saved->title)->toBe('Hello')->and($saved->author)->toBe('Ada Lovelace');
    });

    it('keeps the Parent from a User without reorder', function () {
        kamiPlugin()->forms([Page::class => ReopenedForm::class]);
        $root = resolve(SaveEntry::class)->handle(Page::class, ['title' => 'Root'], locale: 'fr');
        $draft = resolve(SaveEntry::class)->handle(Page::class, ['title' => 'Draft'], locale: 'fr');
        asWriter();

        typePage('page', EditEntry::class, ['record' => $draft->id])->set('data.parent', $root->id)->call('save')->assertHasNoFormErrors();
        typePage('page', CreateEntry::class)->set('data.title', 'New')->set('data.parent', $root->id)->call('create')->assertHasNoFormErrors();

        expect(Entry::query()->withUnpublished()->ofType('page')->whereNotNull('parent_id')->count())->toBe(0);
    });
});

it('makes no link of a Draft row\'s empty date', function () {
    $entry = resolve(SaveEntry::class)->handle(Commission::class, ['title' => 'Refonte'], locale: 'fr');

    projectPage(ListEntries::class)->assertTableColumnExists('published_at', fn (TextColumn $column): bool => $column->isClickDisabled(), $entry);
});
