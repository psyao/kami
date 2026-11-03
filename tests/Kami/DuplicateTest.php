<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Psyao\Kami\Admin\Internal\EntryResource\ListEntries;
use Psyao\Kami\Content\Actions\DuplicateEntry;
use Psyao\Kami\Content\Actions\RestoreEntry;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Actions\TrashEntry;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Events\EntryDuplicated;
use Psyao\Kami\Content\Events\EntryPublished;
use Psyao\Kami\Content\Events\EntrySaved;
use Psyao\Kami\Facades\Kami;
use Psyao\Kami\Routing\Internal\Path;
use Workbench\App\Content\Article;
use Workbench\App\Content\Footer;
use Workbench\App\Content\Home;
use Workbench\App\Content\Label;
use Workbench\App\Content\Page;
use Workbench\App\Content\Project;
use Workbench\App\Content\Tag;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

beforeEach(function () {
    Kami::routes();
});

it('copies every Translation as a Draft with no date, its title marked in its own Locale and its slug made free', function () {
    $save = resolve(SaveEntry::class);
    $source = $save->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada', 'summary' => 'Résumé', 'status' => 'published']);
    $save->handle(Article::class, ['title' => 'Hello', 'summary' => 'Summary', 'status' => 'published', 'published_at' => now()->addDay()], $source, 'en');

    $copy = resolve(DuplicateEntry::class)->handle($source);

    $fr = Entry::query()->withUnpublished()->inLocale('fr')->findOrFail($copy->id);
    $en = Entry::query()->withUnpublished()->inLocale('en')->findOrFail($copy->id);
    expect([$fr->title, $fr->slug, $fr->status, $fr->published_at, $fr->summary, $fr->author])->toBe(['Bonjour (copie)', 'bonjour-copy', 'draft', null, 'Résumé', 'Ada'])
        ->and([$en->title, $en->slug, $en->status, $en->published_at, $en->summary])->toBe(['Hello (copy)', 'hello-copy', 'draft', null, 'Summary']);
    $this->get('/articles/bonjour')->assertOk();
    $this->get('/articles/bonjour-copy')->assertNotFound();
});

it('takes the next free slug, past a Redirect\'s or a 410\'s address', function () {
    $save = resolve(SaveEntry::class);
    $source = $save->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada']);
    resolve(DuplicateEntry::class)->handle($source);
    Path::query()->create(['site' => 'default', 'locale' => 'fr', 'path' => 'articles/bonjour-copy-2', 'entry_id' => null, 'status' => 410]);

    $copy = resolve(DuplicateEntry::class)->handle($source);

    expect($copy->slug)->toBe('bonjour-copy-3')
        ->and(Path::query()->where('path', 'articles/bonjour-copy-2')->value('status'))->toBe(410)
        ->and(Path::query()->where('entry_id', $copy->id)->pluck('path')->all())->toBe(['articles/bonjour-copy-3']);
});

it('keeps a null title and slug null', function () {
    $source = resolve(SaveEntry::class)->handle(Article::class, ['author' => 'Ada']);

    $copy = resolve(DuplicateEntry::class)->handle($source);

    expect([$copy->title, $copy->slug])->toBe([null, null])
        ->and(Path::query()->where('entry_id', $copy->id)->exists())->toBeFalse();
});

it('shortens a long title and slug so the copy\'s fit', function () {
    $long = str_repeat('a', 255);
    $source = resolve(SaveEntry::class)->handle(Article::class, ['title' => $long, 'slug' => $long, 'author' => 'Ada']);

    $copy = resolve(DuplicateEntry::class)->handle($source);

    expect($copy->title)->toBe(str_repeat('a', 247) . ' (copie)')
        ->and($copy->slug)->toBe(str_repeat('a', 250) . '-copy');
});

it('copies Field values and References, a trashed target\'s too, in their order', function () {
    $save = resolve(SaveEntry::class);
    [$one, $two] = [$save->handle(Article::class, ['title' => 'One', 'author' => 'Ada']), $save->handle(Article::class, ['title' => 'Two', 'author' => 'Ada'])];
    $tag = $save->handle(Tag::class, ['title' => 'Vevey']);
    $source = $save->handle(Project::class, ['title' => 'Quai', 'client' => 'Ville', 'links' => [['label' => 'Site', 'url' => 'https://example.test']], 'related' => [$two->id, $one->id], 'tags' => [$tag->id]]);
    resolve(TrashEntry::class)->handle($one);

    $copy = resolve(DuplicateEntry::class)->handle($source);
    resolve(RestoreEntry::class)->handle($one);

    $read = Entry::query()->withUnpublished()->with(['related', 'tags'])->findOrFail($copy->id);
    expect([$read->client, $read->links, $read->address])->toBe(['Ville', [['label' => 'Site', 'url' => 'https://example.test']], ['street' => null, 'city' => 'Vevey']])
        ->and($read->related->pluck('title')->all())->toBe(['Two', 'One'])
        ->and($read->tags->modelKeys())->toBe([$tag->id]);
});

it('sits last under the same Parent, without the children, with the actor as Creator', function () {
    $ada = User::forceCreate(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'secret']);
    $save = resolve(SaveEntry::class);
    $parent = $save->handle(Page::class, ['title' => 'Parent']);
    $source = $save->handle(Page::class, ['title' => 'Source', 'parent' => $parent->id]);
    $save->handle(Page::class, ['title' => 'Child', 'parent' => $source->id]);
    $save->handle(Page::class, ['title' => 'Sibling', 'parent' => $parent->id]);

    $copy = resolve(DuplicateEntry::class)->handle($source, $ada);

    expect(Entry::query()->withUnpublished()->where('parent_id', $parent->id)->ordered()->get()->pluck('title')->all())->toBe(['Source', 'Sibling', 'Source (copie)'])
        ->and(Entry::query()->withUnpublished()->where('parent_id', $copy->id)->exists())->toBeFalse()
        ->and([$copy->created_by, $copy->updated_by])->toBe([$ada->id, $ada->id]);
});

it('dispatches EntryDuplicated only, after commit, and leaves the next visibility change as it was', function () {
    $this->app->useStoragePath($storage = temporaryPath());
    $ada = User::forceCreate(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'secret']);
    $source = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada', 'status' => 'published', 'published_at' => now()->addDay()]);
    File::deleteDirectory($storage);
    Event::fake();

    DB::transaction(function () use ($source, $ada): void {
        $copy = resolve(DuplicateEntry::class)->handle($source, $ada);
        Event::assertNotDispatched(EntryDuplicated::class);
        DB::afterCommit(fn () => Event::assertDispatched(EntryDuplicated::class, fn (EntryDuplicated $event): bool => $event->source->id === $source->id && $event->copy->id === $copy->id && $event->actor === $ada));
    });

    Event::assertDispatchedTimes(EntryDuplicated::class, 1);
    Event::assertNotDispatched(EntrySaved::class);
    Event::assertNotDispatched(EntryPublished::class);
    expect(File::exists($storage))->toBeFalse();
});

it('marks an untranslatable type\'s title in the default Locale and frees its slug in every Locale', function () {
    $source = resolve(SaveEntry::class)->handle(Label::class, ['title' => 'Vert', 'colour' => 'green']);
    $path = Path::query()->where('entry_id', $source->id)->value('path');
    Path::query()->create(['site' => 'default', 'locale' => 'en', 'path' => str_replace('vert', 'vert-copy', (string) $path), 'entry_id' => null, 'status' => 410]);

    $copy = resolve(DuplicateEntry::class)->handle($source);

    expect([$copy->title, $copy->slug, $copy->colour])->toBe(['Vert (copie)', 'vert-copy-2', 'green'])
        ->and(Path::query()->where('entry_id', $copy->id)->orderBy('locale')->pluck('locale')->all())->toBe(['en', 'fr']);
});

it('refuses a Singleton and a Global Set, of which a Site holds only one', function (string $type) {
    $source = resolve(SaveEntry::class)->handle($type, ['title' => 'One']);

    expect(fn () => resolve(DuplicateEntry::class)->handle($source))->toThrow(ValidationException::class, 'only one')
        ->and(Entry::query()->withUnpublished()->count())->toBe(1);
})->with([Home::class, Footer::class]);

it('refuses an Entry in the Trash', function () {
    $source = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada']);
    resolve(TrashEntry::class)->handle($source);

    expect(fn () => resolve(DuplicateEntry::class)->handle($source))->toThrow(InvalidArgumentException::class, 'Trash');
});

it('duplicates from the row and opens the copy\'s edit page', function () {
    $source = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada', 'status' => 'published']);

    $page = entriesPage(ListEntries::class)
        ->callAction(TestAction::make('replicate')->table($source))
        ->assertNotified(__('kami::admin.duplicate.done'));

    $copy = Entry::query()->withUnpublished()->whereKeyNot($source->id)->sole();
    $page->assertRedirect("/admin/articles/{$copy->id}/edit");
    expect([$copy->title, $copy->status, $copy->created_by])->toBe(['Bonjour (copie)', 'draft', auth()->id()]);
});

it('offers Duplicate under create, never on a trashed row', function () {
    $source = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada']);
    $trashed = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Corbeille', 'author' => 'Ada']);
    resolve(TrashEntry::class)->handle($trashed);
    $can = fn (string ...$actions): array => array_map(fn (string $action): string => "kami.entries.article.{$action}", ['view', ...$actions]);

    entriesPage(ListEntries::class, $can('update'))->assertActionHidden(TestAction::make('replicate')->table($source));
    entriesPage(ListEntries::class, $can('create'))->assertActionVisible(TestAction::make('replicate')->table($source))
        ->filterTable('trashed', false)
        ->assertActionHidden(TestAction::make('replicate')->table($trashed));
});
