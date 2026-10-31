<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Psyao\Kami\Admin\Internal\EntryResource\ListEntries;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Internal\ReorderEntries;
use Workbench\App\Content\Article;
use Workbench\App\Content\Project;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

/** @param list<string> $titles */
function projects(array $titles): array
{
    return array_map(fn (string $title): Entry => resolve(SaveEntry::class)->handle(Project::class, ['title' => $title], locale: 'fr'), $titles);
}

/** @return list<string|null> */
function orderedTitles(): array
{
    return Entry::query()->withUnpublished()->inLocale('fr')->ofType(Project::class)->ordered()->get()->pluck('title')->all();
}

it('puts a new Entry of an orderable type last', function () {
    [$first, $second] = projects(['One', 'Two']);

    expect($first->position)->toBe(1)
        ->and($second->position)->toBe(2)
        ->and(orderedTitles())->toBe(['One', 'Two']);
});

it('accepts a position, on a new Entry and on an update, and keeps it when none is given', function () {
    $save = resolve(SaveEntry::class);
    [$first, $second] = projects(['One', 'Two']);
    $third = $save->handle(Project::class, ['title' => 'Three', 'position' => 0], locale: 'fr');

    expect($third->position)->toBe(0)->and(orderedTitles())->toBe(['Three', 'One', 'Two']);

    $save->handle(Project::class, ['position' => 9], $first, 'fr');
    $save->handle(Project::class, ['title' => 'Deux'], $second, 'fr');

    expect(orderedTitles())->toBe(['Three', 'Deux', 'One']);
});

it('refuses an invalid position', function (mixed $position) {
    expect(fn () => resolve(SaveEntry::class)->handle(Project::class, ['title' => 'One', 'position' => $position], locale: 'fr'))
        ->toThrow(ValidationException::class);
})->with(['a word' => 'first', 'negative' => -1, 'leaving no room for a next Entry' => 2147483647]);

it('refuses a position on a type that is not orderable, and gives it none', function () {
    $save = resolve(SaveEntry::class);

    expect(fn () => $save->handle(Article::class, ['title' => 'One', 'position' => 1], locale: 'fr'))->toThrow(ValidationException::class)
        ->and($save->handle(Article::class, ['title' => 'One'], locale: 'fr')->position)->toBe(0);
});

it('breaks a tie by creation', function () {
    $save = resolve(SaveEntry::class);

    foreach (['One', 'Two', 'Three'] as $title) {
        $save->handle(Project::class, ['title' => $title, 'position' => 0], locale: 'fr');
    }

    expect(orderedTitles())->toBe(['One', 'Two', 'Three']);
});

it('rewrites the Positions in the given order, ignoring what is no sibling', function () {
    [$one, $two, $three] = projects(['One', 'Two', 'Three']);
    $article = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Article'], locale: 'fr');

    resolve(ReorderEntries::class)->handle(new Project, [$three->id, $article->id, $one->id, $two->id]);

    expect(orderedTitles())->toBe(['Three', 'One', 'Two'])
        ->and($article->fresh()->position)->toBe(0);
});

it('keeps the others in place when given part of the list', function () {
    [$one, $two, $three, $four] = projects(['One', 'Two', 'Three', 'Four']);

    resolve(ReorderEntries::class)->handle(new Project, [$three->id, $two->id]);

    expect(orderedTitles())->toBe(['One', 'Three', 'Two', 'Four']);
});

it('settles tied Positions on the first reorder', function () {
    $save = resolve(SaveEntry::class);
    $entries = array_map(fn (string $title): Entry => $save->handle(Project::class, ['title' => $title, 'position' => 0], locale: 'fr'), ['One', 'Two', 'Three']);

    resolve(ReorderEntries::class)->handle(new Project, [$entries[1]->id, $entries[0]->id, $entries[2]->id]);

    expect(orderedTitles())->toBe(['Two', 'One', 'Three'])
        ->and(Entry::query()->withUnpublished()->ofType(Project::class)->ordered()->pluck('position')->all())->toBe([1, 2, 3]);
});

it('leaves another Site alone, in the reorder and in the last position', function () {
    [$one, $two, $three] = projects(['One', 'Two', 'Three']);
    DB::table('kami_entries')->where('id', $three->id)->update(['site' => 'other', 'position' => 7]);

    resolve(ReorderEntries::class)->handle(new Project, [$three->id, $two->id, $one->id]);

    expect(DB::table('kami_entries')->where('id', $three->id)->value('position'))->toBe(7)
        ->and($two->fresh()->position)->toBe(1)
        ->and(projects(['Four'])[0]->position)->toBe(3);
});

/** A type's table as a User holding the Role. */
function entriesTable(string $role, string $type = 'project'): Testable
{
    $user = User::forceCreate(['name' => $role, 'email' => "{$role}@example.test", 'password' => 'secret']);
    DB::table('kami_role_user')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->getKey(), 'role' => $role]);
    test()->actingAs($user);
    Filament::setCurrentPanel('admin');
    Filament::setCurrentResourceConfigurationKey($type);

    return Livewire::test(ListEntries::class);
}

it('lists an orderable type in its order, and reorders it under its Permission', function () {
    [$one, $two, $three] = projects(['One', 'Two', 'Three']);

    entriesTable('administrator')
        ->assertCanSeeTableRecords([$one, $two, $three], inOrder: true)
        ->call('reorderTable', [$three->id, $one->id, $two->id])
        ->assertCanSeeTableRecords([$three, $one, $two], inOrder: true);

    expect(orderedTitles())->toBe(['Three', 'One', 'Two']);
});

it('moves a row by the keyboard buttons, within its page', function () {
    [$one, $two, $three] = projects(['One', 'Two', 'Three']);

    entriesTable('administrator')
        ->call('toggleTableReordering')
        ->call('moveTableRecord', $three->id, 'up');

    expect(orderedTitles())->toBe(['One', 'Three', 'Two']);
});

it('reorders one page of the table without touching the other rows', function () {
    $entries = projects(array_map(fn (int $n): string => "P{$n}", range(1, 6)));
    $page = entriesTable('administrator')->set('tableRecordsPerPage', 5)->call('toggleTableReordering');

    $page->assertCanSeeTableRecords(array_slice($entries, 0, 5))->assertCanNotSeeTableRecords([$entries[5]]);
    $page->call('reorderTable', array_reverse(array_map(fn (Entry $entry): string => $entry->id, array_slice($entries, 0, 5))));

    expect(orderedTitles())->toBe(['P5', 'P4', 'P3', 'P2', 'P1', 'P6']);
});

it('refuses reordering without the Permission', function () {
    [$one, $two] = projects(['One', 'Two']);
    $page = entriesTable('editor');

    expect($page->instance()->getTable()->isReorderable())->toBeFalse();

    $page->call('reorderTable', [$two->id, $one->id]);

    expect(orderedTitles())->toBe(['One', 'Two']);
});

it('offers no reordering on a type that is not orderable', function () {
    expect(entriesTable('administrator', 'article')->instance()->getTable()->isReorderable())->toBeFalse();
});

it('reorders what a search shows, leaving the rows between them in place', function () {
    [$one, $two, $three] = projects(['Match one', 'Other', 'Match two']);

    entriesTable('administrator')
        ->searchTable('Match')
        ->call('toggleTableReordering')
        ->assertCanNotSeeTableRecords([$two])
        ->call('reorderTable', [$three->id, $one->id]);

    expect(orderedTitles())->toBe(['Match two', 'Other', 'Match one']);
});

it('writes more Positions than one batch holds', function () {
    $ids = array_map(fn (int $n): string => (string) Str::ulid(), range(1, 501));
    DB::table('kami_entries')->insert(array_map(fn (string $id): array => ['id' => $id, 'site' => 'default', 'type' => 'project'], $ids));

    resolve(ReorderEntries::class)->handle(new Project, array_reverse($ids));

    expect(DB::table('kami_entries')->where('type', 'project')->orderBy('position')->pluck('id')->all())->toBe(array_reverse($ids))
        ->and(DB::table('kami_entries')->where('type', 'project')->max('position'))->toBe(501);
});
