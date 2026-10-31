<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Psyao\Kami\Admin\Internal\EntryResource;
use Psyao\Kami\Admin\Internal\EntryResource\CreateEntry;
use Psyao\Kami\Admin\Internal\EntryResource\EditEntry;
use Psyao\Kami\Admin\Internal\EntryResource\ListEntries;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Internal\ReorderEntries;
use Psyao\Kami\Routing\Internal\Path;
use Workbench\App\Content\Article;
use Workbench\App\Content\Label;
use Workbench\App\Content\Page;
use Workbench\App\Content\Project;
use Workbench\App\Content\Tag;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

function page(string $title, array $values = []): Entry
{
    return resolve(SaveEntry::class)->handle(Page::class, ['title' => $title, ...$values], locale: 'fr');
}

/** @return list<string|null> */
function childrenTitles(?Entry $parent): array
{
    return Entry::query()->withUnpublished()->inLocale('fr')->ofType(Page::class)->where('parent_id', $parent?->id)->ordered()->get()->pluck('title')->all();
}

it('sits an Entry under a Parent, moves it, and takes it back to the root', function () {
    $save = resolve(SaveEntry::class);
    $parent = page('Parent');
    $child = page('Child', ['parent' => $parent->id]);

    expect($child->parent_id)->toBe($parent->id);

    $kept = $save->handle(Page::class, ['title' => 'Renamed'], $child, 'fr');
    expect($kept->parent_id)->toBe($parent->id);

    expect($save->handle(Page::class, ['parent' => null], $kept, 'fr')->parent_id)->toBeNull();
});

it('refuses a Parent that is not an Entry of the type in the Site', function (Closure $parent) {
    expect(fn () => page('Child', ['parent' => $parent()]))->toThrow(function (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('parent');
    });
})->with([
    'unknown' => fn () => fn (): string => '01JZZZZZZZZZZZZZZZZZZZZZZZ',
    'another type' => fn () => fn (): string => resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Article'], locale: 'fr')->id,
    'another Site' => fn () => function (): string {
        $other = page('Elsewhere');
        DB::table('kami_entries')->where('id', $other->id)->update(['site' => 'other']);

        return $other->id;
    },
    'not a string' => fn () => fn (): array => ['a'],
]);

it('refuses a Parent on a type that is not hierarchical', function () {
    $article = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'One'], locale: 'fr');

    expect(fn () => resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Two', 'parent' => $article->id], locale: 'fr'))
        ->toThrow(ValidationException::class);
});

it('refuses a cycle, itself or through a descendant', function () {
    $save = resolve(SaveEntry::class);
    $top = page('Top');
    $middle = page('Middle', ['parent' => $top->id]);
    $bottom = page('Bottom', ['parent' => $middle->id]);

    foreach ([$top, $middle, $bottom] as $target) {
        expect(fn () => $save->handle(Page::class, ['parent' => $target->id], $top, 'fr'))->toThrow(function (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('parent');
        });
    }

    expect($top->fresh()->parent_id)->toBeNull();
});

it('lets the database refuse the hard deletion of a Parent with children', function () {
    $parent = page('Parent');
    page('Child', ['parent' => $parent->id]);

    expect(fn () => DB::table('kami_entries')->where('id', $parent->id)->delete())->toThrow(QueryException::class);
});

it('numbers Positions per Parent, and sends an Entry moved under another Parent last there', function () {
    $save = resolve(SaveEntry::class);
    $one = page('One');
    $two = page('Two');
    $inOne = page('In one', ['parent' => $one->id]);
    $moved = page('Moved', ['parent' => $two->id]);
    $second = page('Second', ['parent' => $two->id]);

    expect([$one->position, $two->position, $inOne->position, $moved->position, $second->position])->toBe([1, 2, 1, 1, 2]);

    $moved = $save->handle(Page::class, ['parent' => $one->id], $moved, 'fr');
    expect($moved->position)->toBe(2)->and(childrenTitles($one))->toBe(['In one', 'Moved']);

    // Saving the same Parent is no move.
    expect($save->handle(Page::class, ['parent' => $one->id, 'title' => 'Still'], $inOne, 'fr')->position)->toBe(1);

    expect($save->handle(Page::class, ['parent' => $two->id, 'position' => 7], $moved, 'fr')->position)->toBe(7);
});

it('reorders the siblings of one Parent only', function () {
    $parent = page('Parent');
    $a = page('A', ['parent' => $parent->id]);
    $b = page('B', ['parent' => $parent->id]);
    $root = page('Root');

    resolve(ReorderEntries::class)->handle(new Page, [$root->id, $b->id, $a->id], $parent->id);

    expect(childrenTitles($parent))->toBe(['B', 'A'])
        ->and(childrenTitles(null))->toBe(['Parent', 'Root']);
});

it('keeps hierarchy and order apart: a hierarchical type alone has Parents and no Position', function () {
    $save = resolve(SaveEntry::class);
    $parent = $save->handle(Tag::class, ['title' => 'Parent']);

    expect($save->handle(Tag::class, ['title' => 'Child', 'parent' => $parent->id])->parent_id)->toBe($parent->id)
        ->and(fn () => $save->handle(Tag::class, ['title' => 'Other', 'position' => 1]))->toThrow(ValidationException::class)
        ->and(fn () => $save->handle(Label::class, ['title' => 'Flat', 'parent' => $parent->id]))->toThrow(ValidationException::class);
});

it('leaves Paths out of it: no ancestor in a Path, and a Parent change moves none', function () {
    $save = resolve(SaveEntry::class);
    $one = page('One');
    $two = page('Two');
    $child = page('Child', ['parent' => $one->id]);
    $path = fn (): array => Path::query()->where('entry_id', $child->id)->pluck('path')->all();

    expect($path())->toBe(['child']);

    $save->handle(Page::class, ['parent' => $two->id], $child, 'fr');

    expect($path())->toBe(['child']);
});

it('reads a Term\'s visible ancestors, nearest first', function () {
    $save = resolve(SaveEntry::class);
    $top = $save->handle(Tag::class, ['title' => 'Top']);
    $hidden = $save->handle(Tag::class, ['title' => 'Hidden', 'parent' => $top->id, 'status' => 'draft']);
    $middle = $save->handle(Tag::class, ['title' => 'Middle', 'parent' => $hidden->id]);
    $bottom = $save->handle(Tag::class, ['title' => 'Bottom', 'parent' => $middle->id]);

    expect($bottom->ancestors()->pluck('title')->all())->toBe(['Middle', 'Top'])
        ->and($top->ancestors())->toBeEmpty();
});

function level(string $role, ?Entry $parent = null, string $type = 'page'): Testable
{
    $user = User::query()->where('email', "{$role}@example.test")->first();

    if ($user === null) {
        $user = User::forceCreate(['name' => $role, 'email' => "{$role}@example.test", 'password' => 'secret']);
        DB::table('kami_role_user')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->getKey(), 'role' => $role]);
    }

    test()->actingAs($user);
    Filament::setCurrentPanel('admin');
    Filament::setCurrentResourceConfigurationKey($type);

    return Livewire::withQueryParams($parent instanceof Entry ? ['parent' => $parent->id] : [])->test(ListEntries::class);
}

it('shows one level at a time, and reorders its siblings', function () {
    $parent = page('Parent');
    $other = page('Other');
    $a = page('A', ['parent' => $parent->id]);
    $b = page('B', ['parent' => $parent->id]);
    $deep = page('Deep', ['parent' => $a->id]);

    level('administrator')->assertCanSeeTableRecords([$parent, $other], inOrder: true)->assertCanNotSeeTableRecords([$a, $b, $deep]);

    level('administrator', $parent)
        ->assertCanSeeTableRecords([$a, $b], inOrder: true)
        ->assertCanNotSeeTableRecords([$parent, $other, $deep])
        ->call('reorderTable', [$b->id, $a->id, $other->id])
        ->assertCanSeeTableRecords([$b, $a], inOrder: true);

    expect(childrenTitles(null))->toBe(['Parent', 'Other']);
});

it('goes down through Children, and back with a breadcrumb', function () {
    $top = page('Top');
    $middle = page('Middle', ['parent' => $top->id]);

    level('administrator')->assertTableActionHasUrl('children', EntryResource::getUrl(parameters: ['parent' => $top->id]), $top);

    $breadcrumbs = level('administrator', $middle)->instance()->getBreadcrumbs();

    expect(array_values($breadcrumbs))->toBe(['Pages', 'Top', 'Middle'])
        ->and(array_keys($breadcrumbs))->toBe([EntryResource::getUrl(), EntryResource::getUrl(parameters: ['parent' => $top->id]), 0]);
});

it('answers 404 for a Parent outside the type or the Site', function () {
    $article = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Article'], locale: 'fr');
    $elsewhere = page('Elsewhere');
    DB::table('kami_entries')->where('id', $elsewhere->id)->update(['site' => 'other']);

    level('administrator', $article)->assertNotFound();
    level('administrator', $elsewhere)->assertNotFound();
});

it('offers no Children on a type that is not hierarchical, and answers 404 for a Parent there', function () {
    $article = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Article'], locale: 'fr');

    level('administrator', type: 'article')->assertTableActionDoesNotExist('children');
    level('administrator', $article, 'article')->assertNotFound();
});

it('lists a hierarchical type that is not orderable by level, with no reordering', function () {
    $save = resolve(SaveEntry::class);
    $parent = $save->handle(Tag::class, ['title' => 'Parent']);
    $child = $save->handle(Tag::class, ['title' => 'Child', 'parent' => $parent->id]);

    $page = level('administrator', $parent, 'tag');

    $page->assertCanSeeTableRecords([$child])->assertCanNotSeeTableRecords([$parent]);
    expect($page->instance()->getTable()->isReorderable())->toBeFalse();
});

it('changes the Parent through the form, under `reorder`', function () {
    $one = page('One');
    $two = page('Two');
    $child = page('Child', ['parent' => $one->id]);

    level('administrator');
    Livewire::test(EditEntry::class, ['record' => $child->id])
        ->assertSchemaStateSet(['parent' => $one->id], 'form')
        ->fillForm(['parent' => $two->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($child->fresh()->parent_id)->toBe($two->id);
});

it('keeps the Parent when the editor may not reorder', function () {
    $one = page('One');
    $two = page('Two');
    $child = page('Child', ['parent' => $one->id]);

    level('editor');
    Livewire::test(EditEntry::class, ['record' => $child->id])
        ->assertFormFieldDisabled('parent')
        ->fillForm(['parent' => $two->id, 'title' => 'Renamed'])
        ->call('save');

    expect($child->fresh()->parent_id)->toBe($one->id);
});

it('shows a cycle on the Parent field', function () {
    $top = page('Top');
    $child = page('Child', ['parent' => $top->id]);

    level('administrator');
    Livewire::test(EditEntry::class, ['record' => $top->id])
        ->fillForm(['parent' => $child->id])
        ->call('save')
        ->assertHasFormErrors(['parent']);
});

it('creates under the Parent shown', function () {
    $parent = page('Parent');

    level('administrator', $parent)->assertActionHasUrl('create', EntryResource::getUrl('create', ['parent' => $parent->id]));

    Livewire::withQueryParams(['parent' => $parent->id])->test(CreateEntry::class)
        ->fillForm(['title' => 'Child'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(childrenTitles($parent))->toBe(['Child']);
});

it('offers an editor who may not reorder no create button under a Parent', function () {
    $parent = page('Parent');

    level('editor', $parent)->assertActionHidden('create');
});

it('reads the table in an exact number of queries, whatever the rows', function () {
    $parent = page('Parent');
    page('A', ['parent' => $parent->id]);
    $count = function () use ($parent): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        level('administrator', $parent);

        return count(DB::getQueryLog());
    };

    $count();
    $first = $count();
    page('B', ['parent' => $parent->id]);
    page('C', ['parent' => $parent->id]);

    expect($first)->toBe(9)->and($count())->toBe($first);
});

it('reads one list on a type no longer hierarchical, whatever Parents its rows kept', function () {
    $save = resolve(SaveEntry::class);
    $one = $save->handle(Project::class, ['title' => 'One'], locale: 'fr');
    $two = $save->handle(Project::class, ['title' => 'Two'], locale: 'fr');
    DB::table('kami_entries')->where('id', $two->id)->update(['parent_id' => $one->id]);

    resolve(ReorderEntries::class)->handle(new Project, [$two->id, $one->id]);

    expect($two->fresh()->position)->toBe(1)
        ->and(ReorderEntries::next(new Project))->toBe(3);
});

it('ignores a Parent that is not a string on the create page', function () {
    level('administrator');

    Livewire::withQueryParams(['parent' => ['x']])->test(CreateEntry::class)->assertSchemaStateSet(['parent' => null], 'form');
});

it('stops at a cycle concurrent moves could close', function () {
    $save = resolve(SaveEntry::class);
    $top = $save->handle(Tag::class, ['title' => 'Top']);
    $bottom = $save->handle(Tag::class, ['title' => 'Bottom', 'parent' => $top->id]);
    DB::table('kami_entries')->where('id', $top->id)->update(['parent_id' => $bottom->id]);

    expect($bottom->ancestors()->pluck('title')->all())->toBe(['Top', 'Bottom']);
});
