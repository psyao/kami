<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Psyao\Kami\Admin\Internal\EntryResource\EditEntry;
use Psyao\Kami\Admin\Internal\EntryResource\ListEntries;
use Psyao\Kami\Content\Actions\DeleteEntry;
use Psyao\Kami\Content\Actions\DeleteTranslation;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Actions\TrashEntry;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Events\EntryDeleted;
use Psyao\Kami\Content\Events\TranslationDeleted;
use Psyao\Kami\Content\Internal\PreviewLink;
use Psyao\Kami\Content\Internal\Visibility;
use Psyao\Kami\Facades\Kami;
use Psyao\Kami\Sites\Internal\Site;
use Workbench\App\Content\Article;
use Workbench\App\Content\Page;
use Workbench\App\Content\Project;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

beforeEach(function () {
    Kami::routes();
});

/** A published Article in the Trash, with an English Translation too. */
function trashedArticle(string $title = 'Bonjour', string $english = 'Hello'): Entry
{
    $entry = resolve(SaveEntry::class)->handle(Article::class, ['title' => $title, 'author' => 'Ada', 'status' => 'published']);
    resolve(SaveEntry::class)->handle(Article::class, ['title' => $english, 'author' => 'Ada', 'status' => 'published'], $entry, 'en');
    resolve(TrashEntry::class)->handle($entry);

    return Entry::query()->withUnpublished()->withTrashed()->findOrFail($entry->id);
}

it('deletes a trashed Entry for good, its addresses answering 410 in every Locale', function () {
    $entry = trashedArticle();

    resolve(DeleteEntry::class)->handle($entry);

    expect(Entry::query()->withUnpublished()->withTrashed()->whereKey($entry->id)->exists())->toBeFalse();
    $this->get('/articles/bonjour')->assertGone();
    $this->get('/en/articles/hello')->assertGone();
});

it('turns its addresses into 301s to a replacement, at its current URL in the same Locale, and a 404 where it is not visible', function () {
    $entry = trashedArticle();
    $replacement = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Salut', 'author' => 'Ada', 'status' => 'published']);
    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Hi'], $replacement, 'en');

    resolve(DeleteEntry::class)->handle($entry, $replacement);

    $this->get('/articles/bonjour')->assertMovedPermanently()->assertRedirect('http://localhost/articles/salut');
    $this->get('/en/articles/hello')->assertNotFound();

    resolve(SaveEntry::class)->handle(Article::class, ['status' => 'published'], $replacement, 'en');
    $this->get('/en/articles/hello')->assertMovedPermanently()->assertRedirect('http://localhost/en/articles/hi');
});

it('refuses a Parent with trashed children, naming them, and deletes nothing', function () {
    $save = resolve(SaveEntry::class);
    $parent = $save->handle(Page::class, ['title' => 'Parent']);
    $child = $save->handle(Page::class, ['title' => 'Child', 'parent' => $parent->id]);
    resolve(TrashEntry::class)->handle($child);
    resolve(TrashEntry::class)->handle($parent);

    expect(fn () => resolve(DeleteEntry::class)->handle($parent))->toThrow(ValidationException::class, 'Child');
    expect(Entry::query()->withUnpublished()->withTrashed()->whereKey($parent->id)->exists())->toBeTrue();

    resolve(DeleteEntry::class)->handle($child);
    resolve(DeleteEntry::class)->handle($parent);
    expect(Entry::query()->withUnpublished()->withTrashed()->exists())->toBeFalse();
});

it('refuses an Entry outside the Trash, and one replacing itself', function () {
    $entry = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada']);
    $trashed = trashedArticle('Salut', 'Hi');

    expect(fn () => resolve(DeleteEntry::class)->handle($entry))->toThrow(InvalidArgumentException::class, 'Trash')
        ->and(fn () => resolve(DeleteEntry::class)->handle($trashed, $trashed))->toThrow(InvalidArgumentException::class, 'itself');
});

it('removes the References from and to the Entry, links kept for it in the Trash included', function () {
    $entry = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada', 'status' => 'published']);
    $project = resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Quai', 'related' => [$entry->id]]);
    resolve(TrashEntry::class)->handle($entry);

    resolve(DeleteEntry::class)->handle($entry);

    expect(Entry::query()->withUnpublished()->with('related')->findOrFail($project->id)->related)->toBeEmpty();
});

it('dispatches EntryDeleted once with values, after commit, and rewrites the next visibility change', function () {
    Event::fake([EntryDeleted::class]);
    $this->freezeSecond();
    $this->app->useStoragePath($storage = temporaryPath());
    $ada = User::forceCreate(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'secret']);
    $sooner = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Sooner', 'author' => 'Ada', 'status' => 'published', 'published_at' => now()->addDay()]);
    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Later', 'author' => 'Ada', 'status' => 'published', 'published_at' => now()->addWeek()]);
    resolve(TrashEntry::class)->handle($sooner);
    File::delete(storage_path('framework/kami/visibility-default.json'));

    resolve(DeleteEntry::class)->handle($sooner, actor: $ada);

    expect(Visibility::nextChangeAt(Site::current())?->toIso8601String())->toBe(now()->addWeek()->toIso8601String());
    Event::assertDispatchedTimes(EntryDeleted::class, 1);
    Event::assertDispatched(EntryDeleted::class, fn (EntryDeleted $event): bool => $event->entryId === $sooner->id && $event->type === 'article' && $event->site === 'default' && $event->replacementId === null && $event->actor === $ada);

    File::deleteDirectory($storage);
});

it('answers a 410 in one query and a 301 in as many as a page\'s Entry takes', function () {
    $replacement = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Salut', 'author' => 'Ada', 'status' => 'published']);
    resolve(DeleteEntry::class)->handle(trashedArticle(), $replacement);
    resolve(DeleteEntry::class)->handle(trashedArticle('Adieu', 'Bye'));

    DB::enableQueryLog();
    $this->get('/articles/adieu')->assertGone();
    // The Path.
    expect(DB::getQueryLog())->toHaveCount(1);

    DB::flushQueryLog();
    $this->get('/articles/bonjour')->assertMovedPermanently();
    // The Path, then the replacement with its visibility, its Translations, its type rows.
    expect(DB::getQueryLog())->toHaveCount(4);
});

it('deletes an Entry holding Preview links, which answer 404 since', function () {
    $entry = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada']);
    $url = PreviewLink::issue($entry, 'fr', 7, null);
    resolve(TrashEntry::class)->handle($entry);

    resolve(DeleteEntry::class)->handle($entry);

    $this->get(parse_url($url, PHP_URL_PATH))->assertNotFound();
});

it('rewrites the addresses redirected to an Entry when it is deleted in turn', function () {
    $entry = trashedArticle();
    $replacement = trashedArticle('Salut', 'Hi');
    resolve(DeleteEntry::class)->handle($entry, $replacement);

    resolve(DeleteEntry::class)->handle($replacement);

    $this->get('/articles/bonjour')->assertGone();
    $this->get('/articles/salut')->assertGone();
});

it('lets a new Entry take an address left by a 410 or a Redirect', function () {
    $replacement = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Salut', 'author' => 'Ada', 'status' => 'published']);
    resolve(DeleteEntry::class)->handle(trashedArticle(), $replacement);
    resolve(DeleteEntry::class)->handle(trashedArticle('Adieu', 'Bye'));

    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada', 'status' => 'published']);
    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Adieu', 'author' => 'Ada', 'status' => 'published']);

    $this->get('/articles/bonjour')->assertOk();
    $this->get('/articles/adieu')->assertOk();
    $this->get('/en/articles/hello')->assertNotFound();
    $this->get('/en/articles/bye')->assertGone();
});

it('deletes a never-published Translation and frees its Path, keeping the others', function () {
    $entry = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada', 'status' => 'published']);
    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Hello'], $entry, 'en');

    resolve(DeleteTranslation::class)->handle($entry, 'en');

    $read = Entry::query()->withUnpublished()->findOrFail($entry->id);
    expect($read->translations->pluck('locale')->all())->toBe(['fr'])
        ->and($read->author)->toBe('Ada');
    $this->get('/articles/bonjour')->assertOk();
    $other = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Hello', 'author' => 'Bob'], locale: 'en');
    expect(Entry::query()->withUnpublished()->inLocale('en')->findOrFail($other->id)->slug)->toBe('hello');
});

it('refuses a Translation once published, and the last one, with the reason', function () {
    $entry = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada']);
    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Hello', 'status' => 'published'], $entry, 'en');
    resolve(SaveEntry::class)->handle(Article::class, ['status' => 'draft'], $entry, 'en');

    expect(fn () => resolve(DeleteTranslation::class)->handle($entry, 'en'))->toThrow(ValidationException::class, 'published')
        ->and(fn () => resolve(DeleteTranslation::class)->handle(resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Seul', 'author' => 'Ada']), 'fr'))->toThrow(ValidationException::class, 'only');
});

it('records the actor, dispatches TranslationDeleted once with values, and rewrites the next visibility change', function () {
    Event::fake([TranslationDeleted::class]);
    $this->freezeSecond();
    $this->app->useStoragePath($storage = temporaryPath());
    $ada = User::forceCreate(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'secret']);
    $entry = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada']);
    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Hello'], $entry, 'en');
    File::delete(storage_path('framework/kami/visibility-default.json'));
    $this->travel(1)->minute();

    resolve(DeleteTranslation::class)->handle($entry, 'en', $ada);

    $stored = Entry::query()->withUnpublished()->sole();
    expect($stored->updated_by)->toBe($ada->id)
        ->and($stored->updated_at?->toIso8601String())->toBe(now()->toIso8601String())
        ->and(File::exists(storage_path('framework/kami/visibility-default.json')))->toBeTrue();
    Event::assertDispatchedTimes(TranslationDeleted::class, 1);
    Event::assertDispatched(TranslationDeleted::class, fn (TranslationDeleted $event): bool => $event->entryId === $entry->id && $event->type === 'article' && $event->site === 'default' && $event->locale === 'en' && $event->actor === $ada);

    File::deleteDirectory($storage);
});

it('deletes from the Trash for good, its addresses redirected to a replacement picked among the type\'s Entries', function () {
    $entry = trashedArticle();
    $replacement = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Salut', 'author' => 'Ada', 'status' => 'published']);

    entriesPage(ListEntries::class)
        ->filterTable('trashed', false)
        ->assertActionVisible(TestAction::make('forceDelete')->table($entry))
        ->callAction(TestAction::make('forceDelete')->table($entry), ['replacement' => $replacement->id])
        ->assertNotified()
        ->assertCanNotSeeTableRecords([$entry]);

    $this->get('/articles/bonjour')->assertRedirect('http://localhost/articles/salut');
});

it('answers 410 by default, and offers the deletion under force-delete only, on trashed rows', function () {
    $entry = trashedArticle();
    $kept = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Salut', 'author' => 'Ada']);
    $can = fn (string ...$actions): array => array_map(fn (string $action): string => "kami.entries.article.{$action}", ['view', ...$actions]);

    entriesPage(ListEntries::class, $can('delete', 'restore'))->filterTable('trashed', false)->assertActionHidden(TestAction::make('forceDelete')->table($entry));
    entriesPage(ListEntries::class, $can('force-delete'))->assertActionHidden(TestAction::make('forceDelete')->table($kept))
        ->filterTable('trashed', false)
        ->callAction(TestAction::make('forceDelete')->table($entry))
        ->assertNotified();

    $this->get('/articles/bonjour')->assertGone();
});

it('shows a refusal on a notification, and deletes nothing', function () {
    $save = resolve(SaveEntry::class);
    $parent = $save->handle(Page::class, ['title' => 'Parent']);
    $child = $save->handle(Page::class, ['title' => 'Child', 'parent' => $parent->id]);
    resolve(TrashEntry::class)->handle($child);
    resolve(TrashEntry::class)->handle($parent);

    entriesPage(ListEntries::class, type: 'page')
        ->filterTable('trashed', false)
        ->callAction(TestAction::make('forceDelete')->table($parent))
        ->assertNotified(__('kami::admin.delete.children', ['children' => 'Child']));

    expect(Entry::query()->withUnpublished()->withTrashed()->whereKey($parent->id)->exists())->toBeTrue();
});

it('deletes a Translation from its edit page, and shows a refusal on a notification', function () {
    $entry = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada', 'status' => 'published']);
    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Hello'], $entry, 'en');

    entriesPage(EditEntry::class, parameters: ['record' => $entry->id])
        ->callAction('deleteTranslation')
        ->assertNotified(__('kami::admin.delete_translation.published'));

    entriesPage(EditEntry::class, parameters: ['record' => $entry->id, 'locale' => 'en'])
        ->callAction('deleteTranslation')
        ->assertNotified(__('kami::admin.delete_translation.done'))
        ->assertRedirect("/admin/articles/{$entry->id}/edit");

    expect(Entry::query()->withUnpublished()->findOrFail($entry->id)->translations->pluck('locale')->all())->toBe(['fr']);
    entriesPage(EditEntry::class, parameters: ['record' => $entry->id, 'locale' => 'en'])->assertActionHidden('deleteTranslation');
});

it('offers the Translation\'s deletion under delete only', function () {
    $entry = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada']);
    $can = fn (string ...$actions): array => array_map(fn (string $action): string => "kami.entries.article.{$action}", ['view', 'update', ...$actions]);

    entriesPage(EditEntry::class, $can(), ['record' => $entry->id])->assertActionHidden('deleteTranslation');
    entriesPage(EditEntry::class, $can('delete'), ['record' => $entry->id])->assertActionVisible('deleteTranslation');
});

it('offers the type\'s Entries outside the Trash as replacements', function () {
    $entry = trashedArticle('Salut A', 'Hi');
    trashedArticle('Salut B', 'Bye');
    $replacement = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Salut C', 'author' => 'Ada']);
    resolve(SaveEntry::class)->handle(Page::class, ['title' => 'Salut D']);

    $page = entriesPage(ListEntries::class)->filterTable('trashed', false)->mountAction(TestAction::make('forceDelete')->table($entry));

    expect(array_column($page->instance()->callSchemaComponentMethod('mountedActionSchema0.replacement', 'getSearchResultsForJs', ['search' => 'Salut']), 'value'))->toBe([$replacement->id]);
});
