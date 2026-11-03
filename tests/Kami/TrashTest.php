<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Psyao\Kami\Admin\Internal\EntryResource\EditEntry;
use Psyao\Kami\Admin\Internal\EntryResource\ListEntries;
use Psyao\Kami\Content\Actions\RestoreEntry;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Actions\TrashEntry;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Events\EntryRestored;
use Psyao\Kami\Content\Events\EntryTrashed;
use Psyao\Kami\Content\Internal\Visibility;
use Psyao\Kami\Content\Term;
use Psyao\Kami\Facades\Kami;
use Psyao\Kami\Routing\Internal\Path;
use Psyao\Kami\Sites\Internal\Site;
use Workbench\App\Content\Article;
use Workbench\App\Content\Footer;
use Workbench\App\Content\Home;
use Workbench\App\Content\Page;
use Workbench\App\Content\Project;
use Workbench\App\Content\Tag;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

beforeEach(function () {
    Kami::routes();
});

function published(string $title): Entry
{
    return resolve(SaveEntry::class)->handle(Article::class, ['title' => $title, 'author' => 'Ada', 'status' => 'published']);
}

it('trashes an Entry in all its Locales: absent from queries, unpublished ones included, and a 404', function () {
    $entry = published('Bonjour');
    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Hello', 'author' => 'Ada', 'status' => 'published'], $entry, 'en');
    $this->get('/en/articles/hello')->assertOk();

    resolve(TrashEntry::class)->handle($entry);

    expect(Entry::query()->whereKey($entry->id)->exists())->toBeFalse()
        ->and(Entry::query()->withUnpublished()->whereKey($entry->id)->exists())->toBeFalse()
        ->and(Entry::query()->withUnpublished()->withTrashed()->whereKey($entry->id)->exists())->toBeTrue();
    $this->get('/articles/bonjour')->assertNotFound();
    $this->get('/en/articles/hello')->assertNotFound();
});

it('keeps a trashed Entry\'s Paths reserved: another Entry cannot take its address', function () {
    published('Bonjour');
    $trashed = Entry::query()->sole();
    resolve(TrashEntry::class)->handle($trashed);

    expect(Path::query()->where('entry_id', $trashed->id)->value('path'))->toBe('articles/bonjour')
        ->and(fn () => published('Bonjour'))->toThrow(ValidationException::class);
});

it('records the actor as Last modifier and dispatches EntryTrashed once, after commit', function () {
    Event::fake([EntryTrashed::class]);
    $ada = User::forceCreate(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'secret']);
    $entry = published('Bonjour');
    $this->travel(1)->minute();

    resolve(TrashEntry::class)->handle($entry, $ada);

    $stored = Entry::query()->withTrashed()->sole();
    expect($stored->updated_by)->toBe($ada->id)
        ->and($stored->updated_at?->toIso8601String())->toBe(now()->toIso8601String());
    Event::assertDispatchedTimes(EntryTrashed::class, 1);
    Event::assertDispatched(EntryTrashed::class, fn (EntryTrashed $event): bool => $event->entry->id === $entry->id && $event->actor === $ada);
});

it('refuses a Singleton and a Global Set, which a Site never loses', function (string $type) {
    $entry = resolve(SaveEntry::class)->handle($type, ['title' => 'One']);

    expect(fn () => resolve(TrashEntry::class)->handle($entry))->toThrow(ValidationException::class, 'never loses');
})->with([Home::class, Footer::class]);

it('refuses a Parent with children outside the Trash, naming them', function () {
    $save = resolve(SaveEntry::class);
    $parent = $save->handle(Page::class, ['title' => 'Parent']);
    $save->handle(Page::class, ['title' => 'Kept', 'parent' => $parent->id]);
    $trashed = $save->handle(Page::class, ['title' => 'Gone', 'parent' => $parent->id]);
    resolve(TrashEntry::class)->handle($trashed);

    expect(fn () => resolve(TrashEntry::class)->handle($parent))->toThrow(ValidationException::class, 'Kept');
    expect(Entry::query()->withUnpublished()->whereKey($parent->id)->exists())->toBeTrue();
});

it('names ten children, then how many more', function () {
    $save = resolve(SaveEntry::class);
    $parent = $save->handle(Page::class, ['title' => 'Parent']);
    foreach (range(1, 12) as $n) {
        $save->handle(Page::class, ['title' => "Child {$n}", 'parent' => $parent->id]);
    }

    expect(fn () => resolve(TrashEntry::class)->handle($parent))->toThrow(ValidationException::class, 'Child 10 and 2 more');
});

it('rewrites the next visibility change, leaving the Trash out', function () {
    $this->freezeSecond();
    $this->app->useStoragePath($storage = temporaryPath());
    $sooner = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Sooner', 'author' => 'Ada', 'status' => 'published', 'published_at' => now()->addDay()]);
    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Later', 'author' => 'Ada', 'status' => 'published', 'published_at' => now()->addWeek()]);

    resolve(TrashEntry::class)->handle($sooner);

    expect(Visibility::nextChangeAt(Site::current())?->toIso8601String())->toBe(now()->addWeek()->toIso8601String());

    File::deleteDirectory($storage);
});

it('reads a trashed target as absent in relations, both ways', function () {
    $save = resolve(SaveEntry::class);
    $article = published('Bonjour');
    $tag = $save->handle(Tag::class, ['title' => 'Vevey']);
    $project = $save->handle(Project::class, ['title' => 'Quai', 'related' => [$article->id], 'tags' => [$tag->id]]);
    $other = $save->handle(Project::class, ['title' => 'Gare', 'tags' => [$tag->id]]);

    resolve(TrashEntry::class)->handle($article);
    resolve(TrashEntry::class)->handle($other);

    $read = Entry::query()->withUnpublished()->with('related')->find($project->id);
    expect($read?->related)->toBeEmpty()
        ->and(Term::query()->find($tag->id)?->entries()->withUnpublished()->get()->modelKeys())->toBe([$project->id]);
});

it('restores an Entry with its states and Paths as they were', function () {
    $entry = published('Bonjour');
    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Hello'], $entry, 'en');
    resolve(TrashEntry::class)->handle($entry);

    resolve(RestoreEntry::class)->handle(Entry::query()->withUnpublished()->withTrashed()->findOrFail($entry->id));

    $this->get('/articles/bonjour')->assertOk();
    $this->get('/en/articles/hello')->assertNotFound();
    expect(Entry::query()->withUnpublished()->inLocale('en')->find($entry->id)?->status)->toBe('draft');
});

it('records the actor and dispatches EntryRestored once, then rewrites the next visibility change', function () {
    Event::fake([EntryRestored::class]);
    $this->freezeSecond();
    $this->app->useStoragePath($storage = temporaryPath());
    $ada = User::forceCreate(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'secret']);
    $entry = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Sooner', 'author' => 'Ada', 'status' => 'published', 'published_at' => now()->addDay()]);
    resolve(TrashEntry::class)->handle($entry);
    expect(Visibility::nextChangeAt(Site::current()))->toBeNull();

    resolve(RestoreEntry::class)->handle($entry, $ada);

    expect(Entry::query()->withUnpublished()->sole()->updated_by)->toBe($ada->id)
        ->and(Visibility::nextChangeAt(Site::current())?->toIso8601String())->toBe(now()->addDay()->toIso8601String());
    Event::assertDispatchedTimes(EntryRestored::class, 1);
    Event::assertDispatched(EntryRestored::class, fn (EntryRestored $event): bool => $event->entry->id === $entry->id && $event->actor === $ada);

    File::deleteDirectory($storage);
});

it('refuses a restore under a trashed Parent, naming it', function () {
    $save = resolve(SaveEntry::class);
    $parent = $save->handle(Page::class, ['title' => 'Parent']);
    $child = $save->handle(Page::class, ['title' => 'Child', 'parent' => $parent->id]);
    resolve(TrashEntry::class)->handle($child);
    resolve(TrashEntry::class)->handle($parent);

    expect(fn () => resolve(RestoreEntry::class)->handle($child))->toThrow(ValidationException::class, 'Parent');
    expect(Entry::query()->withUnpublished()->whereKey($child->id)->exists())->toBeFalse();

    resolve(RestoreEntry::class)->handle($parent);
    resolve(RestoreEntry::class)->handle($child);
    expect(Entry::query()->withUnpublished()->where('parent_id', $parent->id)->pluck('id')->all())->toBe([$child->id]);
});

it('keeps links to a trashed target through a save that cannot see them, and restores them after the given ones', function () {
    $save = resolve(SaveEntry::class);
    [$one, $two, $three] = [published('One'), published('Two'), published('Three')];
    $project = $save->handle(Project::class, ['title' => 'Quai', 'related' => [$one->id, $two->id]]);
    resolve(TrashEntry::class)->handle($one);

    $save->handle(Project::class, ['related' => [$three->id, $two->id]], $project);
    $save->handle(Project::class, ['title' => 'Quai neuf'], $project);
    resolve(RestoreEntry::class)->handle($one);

    $read = Entry::query()->withUnpublished()->with('related')->findOrFail($project->id);
    expect($read->related->pluck('title')->all())->toBe(['Three', 'Two', 'One']);
});

it('keeps them when the Field is emptied too', function () {
    $one = published('One');
    $project = resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Quai', 'related' => [$one->id]]);
    resolve(TrashEntry::class)->handle($one);

    resolve(SaveEntry::class)->handle(Project::class, ['related' => []], $project);
    resolve(RestoreEntry::class)->handle($one);

    expect(Entry::query()->withUnpublished()->with('related')->findOrFail($project->id)->related->modelKeys())->toBe([$one->id]);
});

it('refuses to save a trashed Entry, which has no edit page', function () {
    $entry = published('Bonjour');
    resolve(TrashEntry::class)->handle($entry);

    expect(fn () => resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Encore'], $entry))->toThrow(InvalidArgumentException::class, 'Trash');
});

/** A page of the type's Entries in the admin, for a User holding the Role, or these Permissions under a Role of their own. */
function trashPage(string $page, string | array $role = 'administrator', array $parameters = [], string $type = 'article'): Testable
{
    if (is_array($role)) {
        config(['kami.roles.custom' => $role]);
        $role = 'custom';
    }

    $user = User::forceCreate(['name' => 'Ada Lovelace', 'email' => uniqid() . '@example.test', 'password' => 'secret']);
    DB::table('kami_role_user')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->getKey(), 'role' => $role]);
    config(['app.env' => 'local']);
    test()->actingAs($user);
    Filament::setCurrentPanel('admin');
    Filament::setCurrentResourceConfigurationKey($type);

    return Livewire::test($page, $parameters);
}

it('trashes from the row, finds the Entry through the Trash filter, and restores it', function () {
    $entry = published('Bonjour');

    trashPage(ListEntries::class)
        ->callAction(TestAction::make('delete')->table($entry))
        ->assertNotified()
        ->assertCanNotSeeTableRecords([$entry])
        ->filterTable('trashed', false)
        ->assertCanSeeTableRecords([$entry])
        ->assertActionHidden(TestAction::make('edit')->table($entry))
        ->assertActionHidden(TestAction::make('delete')->table($entry))
        ->callAction(TestAction::make('restore')->table($entry))
        ->assertNotified();

    $this->get('/articles/bonjour')->assertOk();
});

it('warns of what uses the Entry before trashing it', function () {
    $article = published('Bonjour');
    $project = resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Quai', 'related' => [$article->id]]);

    trashPage(ListEntries::class)
        ->mountAction(TestAction::make('delete')->table($article))
        ->assertMountedActionModalSee('Quai');

    trashPage(ListEntries::class, type: 'project')
        ->mountAction(TestAction::make('delete')->table($project))
        ->assertMountedActionModalDontSee('Used by');
});

it('shows a refusal on a notification, and trashes nothing', function () {
    $parent = resolve(SaveEntry::class)->handle(Page::class, ['title' => 'Parent']);
    resolve(SaveEntry::class)->handle(Page::class, ['title' => 'Kept', 'parent' => $parent->id]);

    trashPage(ListEntries::class, type: 'page')
        ->callAction(TestAction::make('delete')->table($parent))
        ->assertNotified(__('kami::admin.trash.children', ['children' => 'Kept']));

    expect(Entry::query()->withUnpublished()->whereKey($parent->id)->exists())->toBeTrue();
});

it('offers Trash under delete and Restore under restore, a visible-to-be Entry\'s under publish too', function () {
    $draft = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Brouillon']);
    $published = published('Bonjour');
    resolve(TrashEntry::class)->handle($published);
    $can = fn (string ...$actions): array => array_map(fn (string $action): string => "kami.entries.article.{$action}", ['view', ...$actions]);

    trashPage(ListEntries::class, $can('update'))->assertActionHidden(TestAction::make('delete')->table($draft));
    trashPage(ListEntries::class, $can('delete'))->assertActionVisible(TestAction::make('delete')->table($draft));
    trashPage(ListEntries::class, $can('restore'))->filterTable('trashed', false)->assertActionHidden(TestAction::make('restore')->table($published));
    trashPage(ListEntries::class, $can('restore', 'publish'))->filterTable('trashed', false)->assertActionVisible(TestAction::make('restore')->table($published));
});

it('answers 404 at a trashed Entry\'s edit URL, and to a page opened before', function () {
    $entry = published('Bonjour');
    $page = trashPage(EditEntry::class, parameters: ['record' => $entry->id]);
    resolve(TrashEntry::class)->handle($entry);

    $page->call('save')->assertNotFound();
    $this->get("/admin/articles/{$entry->id}/edit")->assertNotFound();
    expect(Entry::query()->withUnpublished()->withTrashed()->sole()->title)->toBe('Bonjour');
});

it('shows a restore refused under a Parent trashed since the page opened on a notification', function () {
    $parent = resolve(SaveEntry::class)->handle(Page::class, ['title' => 'Parent']);
    $child = resolve(SaveEntry::class)->handle(Page::class, ['title' => 'Child', 'parent' => $parent->id]);
    resolve(TrashEntry::class)->handle($child);
    trashPage(ListEntries::class, type: 'page');
    $page = Livewire::withQueryParams(['parent' => $parent->id])->test(ListEntries::class)->filterTable('trashed', false);

    resolve(TrashEntry::class)->handle($parent);

    $page->callAction(TestAction::make('restore')->table($child))
        ->assertNotified(__('kami::admin.restore.parent', ['parent' => 'Parent']));

    expect(Entry::query()->withUnpublished()->whereKey($child->id)->exists())->toBeFalse();
});

it('lists the Trash in a fixed number of queries, whatever its size', function (int $entries) {
    for ($i = 1; $i <= $entries; $i++) {
        resolve(TrashEntry::class)->handle(published("Article {$i}"));
    }
    trashPage(ListEntries::class);

    DB::enableQueryLog();
    $this->get('/admin/articles?filters[trashed][value]=0')->assertOk()->assertSee('Article 1');

    expect(DB::getQueryLog())->toHaveCount(4);
})->with([1, 10]);
