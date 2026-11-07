<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psyao\Kami\Admin\Internal\EntryResource\ListEntries;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Actions\TrashEntry;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Internal\Translation;
use Psyao\Kami\Facades\Kami;
use Psyao\Kami\Routing\Internal\Path;
use Workbench\App\Content\Article;
use Workbench\App\Content\Page;
use Workbench\App\Content\Project;

uses(RefreshDatabase::class);

beforeEach(function () {
    Kami::routes();
});

function bulk(string $name): TestAction
{
    return TestAction::make($name)->table()->bulk();
}

/** The report of a bulk action some rows of which were refused: how many succeeded, then each reason with its count. */
function partialReport(int $count, int $total, array $reasons): Notification
{
    return Notification::make()->when($count > 0, fn (Notification $notification) => $notification->warning(), fn (Notification $notification) => $notification->danger())
        ->title(__('kami::admin.bulk.partial', ['count' => $count, 'total' => $total]))
        ->body(collect($reasons)->map(fn (int $n, string $reason): string => '<p>' . __('kami::admin.bulk.reason', ['reason' => e($reason), 'count' => $n]) . '</p>')->implode(''))
        ->persistent();
}

it('trashes the selected rows one by one, and reports the refused one with its reason', function () {
    $save = resolve(SaveEntry::class);
    $parent = $save->handle(Page::class, ['title' => 'Parent']);
    $child = $save->handle(Page::class, ['title' => 'Kept', 'parent' => $parent->id]);
    $other = $save->handle(Page::class, ['title' => 'Other']);

    entriesPage(ListEntries::class, type: 'page')
        ->selectTableRecords([$parent->id, $other->id])
        ->callAction(bulk('delete'))
        ->assertNotified(partialReport(1, 2, [__('kami::admin.trash.children', ['children' => 'Kept']) => 1]));

    expect(Entry::query()->withUnpublished()->pluck('id')->sort()->values()->all())->toBe(collect([$parent->id, $child->id])->sort()->values()->all());
});

function bulkArticle(string $title, string $status = 'draft'): Entry
{
    return resolve(SaveEntry::class)->handle(Article::class, ['title' => $title, 'author' => 'Ada', 'status' => $status]);
}

it('restores and deletes for good the trashed rows only, reporting the others and writing nothing to them', function () {
    $trashed = bulkArticle('Gone');
    $kept = bulkArticle('Kept');
    resolve(TrashEntry::class)->handle($trashed);
    resolve(TrashEntry::class)->handle($restored = bulkArticle('Back'));

    entriesPage(ListEntries::class)->filterTable('trashed', true)
        ->selectTableRecords([$restored->id, $kept->id])
        ->callAction(bulk('restore'))
        ->assertNotified(partialReport(1, 2, [__('kami::admin.bulk.not_trashed') => 1]))
        ->selectTableRecords([$trashed->id, $kept->id])
        ->callAction(bulk('forceDelete'))
        ->assertNotified(partialReport(1, 2, [__('kami::admin.bulk.not_trashed') => 1]));

    expect(Entry::query()->withUnpublished()->get()->map->title->sort()->values()->all())->toBe(['Back', 'Kept'])
        ->and(Path::query()->where('path', 'articles/gone')->value('status'))->toBe(410);
});

it('authorises each row as its single action would: restoring a published Entry takes publish too', function () {
    $draft = bulkArticle('Draft');
    $published = bulkArticle('Published', 'published');
    resolve(TrashEntry::class)->handle($draft);
    resolve(TrashEntry::class)->handle($published);

    entriesPage(ListEntries::class, ['kami.entries.article.view', 'kami.entries.article.restore'])->filterTable('trashed', false)
        ->selectTableRecords([$draft->id, $published->id])
        ->callAction(bulk('restore'))
        ->assertNotified(partialReport(1, 2, [__('kami::admin.bulk.forbidden') => 1]));

    expect(Entry::query()->withUnpublished()->get()->map->title->all())->toBe(['Draft']);
});

it('offers each bulk action under its Permission only', function () {
    $draft = bulkArticle('Draft');
    $can = fn (string ...$actions): array => array_map(fn (string $action): string => "kami.entries.article.{$action}", ['view', 'update', ...$actions]);

    entriesPage(ListEntries::class, $can())
        ->assertActionHidden(bulk('delete'))
        ->assertActionHidden(bulk('publish'))
        ->assertActionHidden(bulk('unpublish'));
    entriesPage(ListEntries::class, $can('delete', 'publish'))
        ->assertActionVisible(bulk('delete'))
        ->assertActionVisible(bulk('publish'));
    entriesPage(ListEntries::class, $can())->filterTable('trashed', false)
        ->assertActionHidden(bulk('restore'))
        ->assertActionHidden(bulk('forceDelete'));
    entriesPage(ListEntries::class, $can('restore', 'force-delete'))->filterTable('trashed', false)
        ->assertActionVisible(bulk('restore'))
        ->assertActionVisible(bulk('forceDelete'));
});

it('hides bulk Publish and Unpublish while the table shows only the Trash, and still refuses a trashed row in a mixed selection', function () {
    $trashed = bulkArticle('Gone');
    $draft = bulkArticle('Kept');
    resolve(TrashEntry::class)->handle($trashed);

    entriesPage(ListEntries::class)->filterTable('trashed', false)
        ->assertActionHidden(bulk('publish'))
        ->assertActionHidden(bulk('unpublish'))
        ->filterTable('trashed', true)
        ->assertActionVisible(bulk('unpublish'))
        ->selectTableRecords([$trashed->id, $draft->id])
        ->callAction(bulk('publish'), ['locale' => 'fr'])
        ->assertNotified(partialReport(1, 2, [__('kami::admin.bulk.trashed') => 1]));

    expect($draft->fresh()->status)->toBe('published');
});

it('names deleting for good in plain words, row and bulk, in English and French', function (string $locale, string $label, string $heading, string $bulkLabel) {
    app()->setLocale($locale);
    $entry = bulkArticle('Bonjour');
    resolve(TrashEntry::class)->handle($entry);

    entriesPage(ListEntries::class)->filterTable('trashed', false)
        ->assertActionHasLabel(bulk('forceDelete'), $bulkLabel)
        ->assertActionHasLabel(TestAction::make('forceDelete')->table($entry), $label)
        ->mountAction(TestAction::make('forceDelete')->table($entry))
        ->assertMountedActionModalSee($heading);
})->with([
    'en' => ['en', 'Delete for good', 'Delete Bonjour for good', 'Delete selected for good'],
    'fr' => ['fr', 'Supprimer définitivement', 'Supprimer définitivement Bonjour', 'Supprimer définitivement la sélection'],
]);

it('publishes the chosen Locale\'s Translations, refusing a row without one, a published one and one missing a required Field', function () {
    $ready = bulkArticle('Prêt');
    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Ready'], $ready, 'en');
    $french = bulkArticle('Français seulement');
    $live = bulkArticle('En ligne');
    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Live', 'status' => 'published'], $live, 'en');
    $incomplete = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Incomplet']);
    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Incomplete'], $incomplete, 'en');

    entriesPage(ListEntries::class)
        ->selectTableRecords([$ready->id, $french->id, $live->id, $incomplete->id])
        ->callAction(bulk('publish'), ['locale' => 'en'])
        ->assertNotified(partialReport(1, 4, [
            __('kami::admin.bulk.no_translation', ['locale' => 'EN']) => 1,
            __('kami::admin.bulk.already_published') => 1,
            __('validation.required', ['attribute' => 'author']) => 1,
        ]));

    $status = fn (Entry $entry, string $locale): ?string => Translation::query()->where('entry_id', $entry->id)->where('locale', $locale)->value('status');
    expect([$status($ready, 'en'), $status($ready, 'fr'), $status($incomplete, 'en')])->toBe(['published', 'draft', 'draft']);
});

it('unpublishes an untranslatable type\'s rows with no Locale to choose', function () {
    $project = resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Bridge', 'status' => 'published']);

    entriesPage(ListEntries::class, type: 'project')
        ->selectTableRecords([$project->id])
        ->mountAction(bulk('unpublish'))
        ->assertActionDataSet([])
        ->callMountedAction()
        ->assertNotified(__('kami::admin.bulk.unpublish_done'));

    expect(Entry::query()->withUnpublished()->sole()->status)->toBe('draft');
});
