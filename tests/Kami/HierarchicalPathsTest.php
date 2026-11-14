<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Psyao\Kami\Content\Actions\DeleteTranslation;
use Psyao\Kami\Content\Actions\DuplicateEntry;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Taxonomy;
use Psyao\Kami\Facades\Kami;
use Psyao\Kami\Routing\Internal\Path;
use Workbench\App\Content\Page;

uses(RefreshDatabase::class);

/** A hierarchical Taxonomy with URLs. */
final class RoutedTag extends Taxonomy
{
    #[Override]
    protected ?string $prefix = 'sujets';

    #[Override]
    protected bool $translatable = false;

    #[Override]
    protected bool $hierarchical = true;
}

beforeEach(function () {
    Kami::routes();
});

/** @param array<string, mixed> $values */
function nested(string $title, ?Entry $parent = null, array $values = []): Entry
{
    return resolve(SaveEntry::class)->handle(Page::class, ['title' => $title, 'status' => 'published', 'parent' => $parent?->id, ...$values], locale: 'fr');
}

it('answers a child of a published Parent under its Parent\'s Path', function () {
    $parent = nested('Parent');
    $child = nested('Child', $parent);
    $grandchild = nested('Grandchild', $child);

    $this->get('/parent/child')->assertOk()->assertSee('Child');
    $this->get('/parent/child/grandchild')->assertOk()->assertSee('Grandchild');
    $this->get('/child')->assertNotFound();

    expect($grandchild->url('fr'))->toBe('http://localhost/parent/child/grandchild');
});

it('hides an Entry under a Draft or Scheduled ancestor, and answers again once it is visible, with no Tick', function (string $state) {
    $this->travelTo('2026-11-14 10:00:00');
    $top = nested('Top');
    $middle = nested('Middle', $top);
    $bottom = nested('Bottom', $middle);
    $hide = [
        'draft' => fn () => resolve(SaveEntry::class)->handle(Page::class, ['status' => 'draft'], $middle, 'fr'),
        'scheduled' => fn () => resolve(SaveEntry::class)->handle(Page::class, ['published_at' => now()->addHour()], $middle, 'fr'),
    ][$state];
    $hide();

    $this->get('/top/middle/bottom')->assertNotFound();
    expect(Entry::query()->findOrFail($bottom->id)->url('fr'))->toBeNull()
        ->and(Entry::query()->findOrFail($top->id)->url('fr'))->toBe('http://localhost/top');

    if ($state === 'scheduled') {
        $this->travel(2)->hours();
        $this->get('/top/middle/bottom')->assertOk();
        expect(Entry::query()->findOrFail($bottom->id)->url('fr'))->toBe('http://localhost/top/middle/bottom');
    }
})->with(['draft', 'scheduled']);

it('moves the subtree with a renamed or moved Parent, a 301 at each once-public address, a never-public one rewritten in place', function () {
    $save = resolve(SaveEntry::class);
    $parent = nested('Parent');
    $child = nested('Child', $parent);
    $draft = nested('Draft', $child, ['status' => 'draft']);
    $other = nested('Other');

    $save->handle(Page::class, ['slug' => 'renamed'], $parent, 'fr');

    $this->get('/renamed/child')->assertOk();
    $this->get('/parent/child')->assertRedirect('http://localhost/renamed/child')->assertStatus(301);
    $this->get('/parent')->assertRedirect('http://localhost/renamed')->assertStatus(301);

    $save->handle(Page::class, ['parent' => $other->id], $child, 'fr');

    $this->get('/other/child')->assertOk();
    $this->get('/parent/child')->assertRedirect('http://localhost/other/child');
    $this->get('/renamed/child')->assertRedirect('http://localhost/other/child');
    expect(Path::query()->where('entry_id', $draft->id)->get(['locale', 'path', 'status'])->toArray())
        ->toBe([['locale' => 'fr', 'path' => 'other/child/draft', 'status' => 200]]);
});

it('moves a child in every Locale with its Parent, a Translation\'s own slug in its Locale only', function () {
    $save = resolve(SaveEntry::class);
    $parent = nested('Parent');
    $save->handle(Page::class, ['title' => 'Parent EN', 'slug' => 'parent-en', 'status' => 'published'], $parent, 'en');
    $child = nested('Enfant');
    $save->handle(Page::class, ['title' => 'Child', 'status' => 'published'], $child, 'en');
    $save->handle(Page::class, ['parent' => $parent->id], $child, 'fr');

    $this->get('/parent/enfant')->assertOk();
    $this->get('/en/parent-en/child')->assertOk();
    $this->get('/en/child')->assertRedirect('http://localhost/en/parent-en/child');

    $save->handle(Page::class, ['slug' => 'parent-renamed'], $parent, 'en');

    $this->get('/en/parent-renamed/child')->assertOk();
    $this->get('/parent/enfant')->assertOk();
});

it('gives a child no Path in a Locale where an ancestor has no slug, and one once it has', function () {
    $save = resolve(SaveEntry::class);
    $parent = nested('Parent');
    $child = nested('Enfant', $parent);
    $save->handle(Page::class, ['title' => 'Child', 'status' => 'published'], $child, 'en');

    expect(Path::query()->where(['entry_id' => $child->id, 'locale' => 'en'])->exists())->toBeFalse();

    $save->handle(Page::class, ['title' => 'Parent', 'status' => 'published'], $parent, 'en');

    $this->get('/en/parent/child')->assertOk();
});

it('refuses a move whose subtree lands on a taken address, on the slug', function () {
    $parent = nested('Parent');
    nested('Child', $parent);
    nested('Taken', values: ['slug' => 'other/child']);

    $child = nested('Child', nested('Elsewhere'));

    expect(fn () => resolve(SaveEntry::class)->handle(Page::class, ['slug' => 'other'], $parent, 'fr'))->toThrow(function (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('slug');
    })->and(fn () => resolve(SaveEntry::class)->handle(Page::class, ['parent' => $parent->id], $child, 'fr'))->toThrow(function (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('parent');
    });
    $this->get('/parent/child')->assertOk();
    $this->get('/elsewhere/child')->assertOk();
});

it('refuses a Path longer than its column, ancestors\' slugs included, and answers 404 past that length with no query', function () {
    $parent = nested('Parent', values: ['slug' => str_repeat('a', 255)]);

    expect(fn () => nested('Child', $parent, ['slug' => str_repeat('b', 250)]))->toThrow(function (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('slug');
    });

    DB::enableQueryLog();
    $this->get('/' . str_repeat('a/', 300))->assertNotFound();
    expect(collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'kami_paths')))->toBeEmpty();
});

it('gives a copy of a nested Entry its Path under the Parent\'s', function () {
    $parent = nested('Parent');
    nested('Child', $parent);
    $copy = resolve(DuplicateEntry::class)->handle(nested('Child copy source', $parent, ['slug' => 'child-copy']));

    expect(Path::query()->where('entry_id', $copy->id)->pluck('path')->all())->toBe(['parent/child-copy-copy']);
});

it('takes its descendants\' Paths in a Locale with a deleted ancestor Translation', function () {
    $save = resolve(SaveEntry::class);
    $parent = nested('Parent');
    $save->handle(Page::class, ['title' => 'Parent'], $parent, 'en');
    $child = nested('Enfant', $parent);
    $save->handle(Page::class, ['title' => 'Child'], $child, 'en');

    expect(Path::query()->where(['entry_id' => $child->id, 'locale' => 'en'])->value('path'))->toBe('parent/child');

    resolve(DeleteTranslation::class)->handle($parent, 'en');

    expect(Path::query()->where(['entry_id' => $child->id, 'locale' => 'en'])->exists())->toBeFalse();
});

it('reads no descendant on a save that moves no Path', function () {
    $parent = nested('Parent');
    nested('Child', $parent);

    DB::enableQueryLog();
    resolve(SaveEntry::class)->handle(Page::class, ['body' => 'Changed'], $parent, 'fr');

    expect(collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], '"parent_id" in')))->toBeEmpty();
});

it('builds a hierarchical Taxonomy\'s Paths the same way', function () {
    config(['kami.types' => [...config('kami.types'), RoutedTag::class]]);
    $save = resolve(SaveEntry::class);
    $top = $save->handle(RoutedTag::class, ['title' => 'Php']);
    $bottom = $save->handle(RoutedTag::class, ['title' => 'Symfony', 'parent' => $top->id]);

    expect($bottom->url('fr'))->toBe('http://localhost/sujets/php/symfony')
        ->and($bottom->url('en'))->toBe('http://localhost/en/sujets/php/symfony');
});

it('resolves a nested Entry in an exact number of queries, whatever its depth', function () {
    $parent = nested('One');
    $deep = nested('Four', nested('Three', nested('Two', $parent)));
    $count = function (string $path): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($path)->assertOk();

        return count(DB::getQueryLog());
    };

    // The Path and its prefixes with their visibility, the Entry with its visibility, its Translations, its type rows, the head's look for the
    // Site settings, its Social image Reference; `url()`'s own Paths and its ancestors', read once for every Locale; the layout's Site settings.
    expect($count('/one/two'))->toBe(9)
        ->and($count('/one/two/three/four'))->toBe(9);
});
