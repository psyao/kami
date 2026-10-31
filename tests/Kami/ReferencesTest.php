<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\RelationNotFoundException;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Fields\Boolean;
use Psyao\Kami\Content\Fields\Entries;
use Psyao\Kami\Content\Fields\Group;
use Psyao\Kami\Content\Fields\Repeater;
use Psyao\Kami\Content\Fields\Text;
use Psyao\Kami\Content\GlobalSet;
use Psyao\Kami\Content\Internal\ContentTypes;
use Workbench\App\Content\Article;
use Workbench\App\Content\Page;
use Workbench\App\Content\Project;

uses(RefreshDatabase::class);

/** Relations only, so no table; what each test declares in `$declared`. */
final class Linked extends ContentType
{
    /** @var array<mixed> */
    public static array $declared = [];

    public function fields(): array
    {
        return self::$declared; // @phpstan-ignore return.type
    }
}

final class LinkedSet extends GlobalSet
{
    public function fields(): array
    {
        return [Entries::make('related')->of(Article::class)->required()];
    }
}

beforeEach(function () {
    Linked::$declared = [Entries::make('related')->of(Article::class, Page::class), Entries::make('local')->of(Article::class)->translatable()];
    config(['kami.types' => [Article::class, Page::class, Linked::class]]);
});

function linkTarget(string $title, string $status = 'published', string $locale = 'fr'): Entry
{
    return resolve(SaveEntry::class)->handle(Article::class, ['title' => $title, 'status' => $status, 'author' => 'Ada'], locale: $locale);
}

/** @param array<string, mixed> $values */
function saveLinked(array $values, ?Entry $entry = null, string $locale = 'fr'): Entry
{
    return resolve(SaveEntry::class)->handle(Linked::class, ['title' => 'Source', 'status' => 'published', ...$values], $entry, $locale);
}

/** @param array<string, mixed> $values */
function linkedErrors(array $values): array
{
    try {
        saveLinked($values);
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

/** @return list<string> */
function titlesOf(iterable $entries): array
{
    return collect($entries)->map(fn (Entry $entry) => $entry->title)->values()->all();
}

function declaresLinked(array $fields): void
{
    Linked::$declared = $fields;
    resolve(ContentTypes::class)->all();
}

function readLinked(?string $locale = null): Entry
{
    $query = Entry::query()->ofType('linked')->with('related', 'local');

    return ($locale === null ? $query : $query->inLocale($locale))->sole();
}

it('allows exactly one target through the CHECK', function () {
    $entry = linkTarget('Target')->id;

    // In a savepoint: a refused statement aborts PostgreSQL's whole transaction otherwise.
    $insert = fn (array $row) => DB::transaction(fn () => DB::table('kami_references')->insert(['id' => (string) str()->ulid(), 'source_entry_id' => $entry, 'field' => 'x', 'position' => 0, ...$row]));

    expect(fn () => $insert([]))->toThrow(QueryException::class)
        ->and(fn () => $insert(['target_entry_id' => $entry, 'target_media_id' => (string) str()->ulid()]))->toThrow(QueryException::class);

    $insert(['target_entry_id' => $entry]);
    $insert(['target_media_id' => (string) str()->ulid()]);

    expect(DB::table('kami_references')->count())->toBe(2);
});

it('reads targets in pick order', function () {
    [$one, $two, $three] = [linkTarget('One'), linkTarget('Two'), linkTarget('Three')];
    saveLinked(['related' => [$three->id, $one->id, $two->id]]);

    $related = readLinked()->related;

    expect($related)->toBeInstanceOf(Collection::class)
        ->and(titlesOf($related))->toBe(['Three', 'One', 'Two']);
});

it('rebuilds a given Field only, and a blank list stores no row', function () {
    [$one, $two] = [linkTarget('One'), linkTarget('Two')];
    $source = saveLinked(['related' => [$one->id, $two->id], 'local' => [$one->id]]);

    saveLinked(['related' => [$two->id]], $source);
    expect(titlesOf(readLinked()->related))->toBe(['Two'])
        ->and(titlesOf(readLinked()->local))->toBe(['One']);

    saveLinked(['related' => []], $source);
    expect(readLinked()->related)->toBeEmpty()
        ->and(DB::table('kami_references')->where('field', 'related')->count())->toBe(0);
});

it('stores a shared Reference with a null locale and a translatable one with its own', function () {
    $one = linkTarget('One');
    saveLinked(['related' => [$one->id], 'local' => [$one->id]]);

    expect(DB::table('kami_references')->where('field', 'related')->value('locale'))->toBeNull()
        ->and(DB::table('kami_references')->where('field', 'local')->value('locale'))->toBe('fr');
});

it('refuses an unknown id, a wrong type, another Site, a duplicate and a value not a list, on the Field', function (Closure $value) {
    expect(array_keys(linkedErrors(['related' => $value()])))->toBe(['related']);
})->with([
    'unknown' => fn () => fn () => ['01JAAAAAAAAAAAAAAAAAAAAAAA'],
    'wrong type' => fn () => fn () => [resolve(SaveEntry::class)->handle(Linked::class, ['title' => 'Other'])->id],
    'other Site' => fn () => function () {
        $entry = linkTarget('Elsewhere');
        DB::table('kami_entries')->where('id', $entry->id)->update(['site' => 'other']);

        return [$entry->id];
    },
    'duplicate' => fn () => function () {
        $id = linkTarget('One')->id;

        return [$id, $id];
    },
    'no list' => fn () => fn () => 'nope',
    'not an id' => fn () => fn () => [['nested']],
]);

it('accepts a Draft target, which public reads leave out', function () {
    [$draft, $later, $shown] = [linkTarget('Draft', 'draft'), resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Later', 'author' => 'Ada', 'status' => 'published', 'published_at' => now()->addDay()], locale: 'fr'), linkTarget('Shown')];
    saveLinked(['related' => [$draft->id, $later->id, $shown->id]]);

    expect(titlesOf(readLinked()->related))->toBe(['Shown'])
        ->and(titlesOf(Entry::query()->withUnpublished()->ofType('linked')->with('related')->sole()->related))->toBe(['Draft', 'Later', 'Shown']);
});

it('leaves out a target not visible in the Locale read', function () {
    [$french, $both] = [linkTarget('Français'), linkTarget('Les deux')];
    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Both', 'status' => 'published'], $both, 'en');
    $source = saveLinked(['related' => [$french->id, $both->id]]);
    saveLinked(['title' => 'Source', 'status' => 'published'], $source, 'en');

    expect(titlesOf(readLinked('en')->related))->toBe(['Both']);
});

it('falls back one hop from a blank translatable relation, from a visible default Translation only', function () {
    $both = linkTarget('Cible');
    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Target', 'status' => 'published'], $both, 'en');
    $source = saveLinked(['local' => [$both->id]]);
    saveLinked(['title' => 'Source'], $source, 'en');

    $read = readLinked('en');
    expect(titlesOf($read->local))->toBe(['Target'])
        ->and($read->fallbackLocale('local'))->toBe('fr');

    saveLinked(['status' => 'draft'], $source, 'fr');

    $read = readLinked('en');
    expect($read->local)->toBeEmpty()
        ->and($read->fallbackLocale('local'))->toBeNull();
});

it('does not fall back from a filled relation whose targets are all hidden', function () {
    [$shown, $draft] = [linkTarget('Shown'), linkTarget('Draft', 'draft')];
    $source = saveLinked(['local' => [$shown->id]]);
    saveLinked(['local' => [$draft->id]], $source, 'en');

    expect(readLinked('en')->local)->toBeEmpty();
});

it('falls back after a reload of an Entry whose Locale was emptied since', function () {
    [$both, $other] = [linkTarget('Cible'), linkTarget('Autre')];
    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Target', 'author' => 'Ada', 'status' => 'published'], $both, 'en');
    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Other', 'author' => 'Ada', 'status' => 'published'], $other, 'en');
    $source = saveLinked(['local' => [$both->id]]);
    saveLinked(['local' => [$other->id]], $source, 'en');
    $read = Entry::query()->ofType('linked')->inLocale('en')->with('local')->get();
    expect(titlesOf($read->first()->local))->toBe(['Other']);

    saveLinked(['local' => []], $source, 'en');
    $read->load('local');

    expect(titlesOf($read->first()->local))->toBe(['Target']);
});

it('loads with one query for the rows and the usual ones per target type', function () {
    [$one, $two] = [linkTarget('One'), linkTarget('Two')];
    $page = resolve(SaveEntry::class)->handle(Page::class, ['title' => 'Page', 'status' => 'published'], locale: 'fr');
    saveLinked(['related' => [$one->id, $page->id]]);
    saveLinked(['title' => 'Second', 'related' => [$two->id]]);

    DB::enableQueryLog();
    $entries = Entry::query()->ofType('linked')->with('related')->get();
    array_map(fn (Entry $entry) => $entry->related->each(fn (Entry $target) => $target->title), $entries->all());

    // Sources, their Translations; rows; targets, their Translations, one type table per target type.
    expect(DB::getQueryLog())->toHaveCount(7);
});

it('throws on an unloaded read among several Entries under strict mode, and loads a single one', function () {
    $one = linkTarget('One');
    saveLinked(['related' => [$one->id]]);
    saveLinked(['title' => 'Second']);

    expect(fn () => Entry::query()->ofType('linked')->get()->first()->related)->toThrow(LazyLoadingViolationException::class)
        ->and(titlesOf(Entry::query()->ofType('linked')->first()->related))->toBe(['One']);
});

it('keeps Eloquent\'s error for a name no type declares', function () {
    saveLinked([]);

    expect(fn () => Entry::query()->ofType('linked')->with('nothing')->get())->toThrow(RelationNotFoundException::class);
});

it('loads through load() and nested relation Fields', function () {
    Linked::$declared = [Entries::make('related')->of(Linked::class), Entries::make('local')->of(Linked::class)->translatable()];
    $leaf = saveLinked(['title' => 'Leaf']);
    $middle = saveLinked(['title' => 'Middle', 'related' => [$leaf->id]]);
    $top = saveLinked(['title' => 'Top', 'related' => [$middle->id]]);

    $read = Entry::query()->with('related.related')->findOrFail($top->id);
    expect($read->related->first()->relationLoaded('related'))->toBeTrue()
        ->and(titlesOf($read->related->first()->related))->toBe(['Leaf']);

    $loaded = Entry::query()->whereKey($top->id)->get()->load('related');
    expect(titlesOf($loaded->first()->related))->toBe(['Middle']);
});

it('removes References when a target or a source is hard-deleted', function () {
    [$one, $two] = [linkTarget('One'), linkTarget('Two')];
    $source = saveLinked(['related' => [$one->id, $two->id]]);

    // A Path holds its Entry back (ADR 0005).
    DB::table('kami_paths')->delete();
    $one->delete();
    expect(titlesOf(readLinked()->related))->toBe(['Two']);

    $source->delete();
    expect(DB::table('kami_references')->count())->toBe(0);
});

it('requires a required relation to publish, stored rows included', function () {
    Linked::$declared = [Entries::make('related')->of(Article::class)->required()];
    $one = linkTarget('One');

    expect(array_keys(linkedErrors([])))->toBe(['related']);

    $source = saveLinked(['status' => 'draft']);
    saveLinked(['status' => 'draft', 'related' => [$one->id]], $source);

    expect(saveLinked([], $source)->status)->toBe('published');
});

it('clears a hidden relation in every Locale', function () {
    $project = fn (array $values, ?Entry $entry = null) => resolve(SaveEntry::class)->handle(ProjectWithHiddenRelation::class, ['title' => 'P', ...$values], $entry, 'fr');
    config(['kami.types' => [Article::class, ProjectWithHiddenRelation::class]]);

    $one = linkTarget('One');
    $source = $project(['featured' => true, 'related' => [$one->id]]);
    expect(DB::table('kami_references')->count())->toBe(1);

    $project(['featured' => false], $source);
    expect(DB::table('kami_references')->count())->toBe(0);
});

/** On `Project`'s table, a relation shown by its Boolean. */
final class ProjectWithHiddenRelation extends ContentType
{
    public function handle(): string
    {
        return 'project';
    }

    public function fields(): array
    {
        return [Boolean::make('featured'), Entries::make('related')->of(Article::class)->visibleWhen('featured', true)];
    }
}

it('refuses what a relation cannot honour, at first read', function (Closure $fields, string $message) {
    expect(fn () => declaresLinked($fields()))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'no target' => [fn () => [Entries::make('related')], 'links to nothing'],
    'undeclared target' => [fn () => [Entries::make('related')->of(Project::class)], 'not listed in kami.types'],
    'filterable' => [fn () => [Entries::make('related')->of(Article::class)->filterable()], 'cannot be filterable'],
    'default' => [fn () => [Entries::make('related')->of(Article::class)->default(['x'])], 'cannot take a default'],
    'in a Repeater' => [fn () => [Repeater::make('rows', [Entries::make('related')->of(Article::class)])], 'cannot hold the relation'],
    'in a Group' => [fn () => [Group::make('rows', [Entries::make('related')->of(Article::class)])], 'cannot hold the relation'],
    'as a target' => [fn () => [Entries::make('related')->of(Article::class), Text::make('note')->visibleWhenFilled('related')], 'according to the relation'],
]);

it('refuses a required relation on a Global Set', function () {
    config(['kami.types' => [Article::class, LinkedSet::class]]);

    expect(fn () => resolve(ContentTypes::class)->all())->toThrow(InvalidArgumentException::class, 'a relation takes no default');
});

it('gives a relation Field no column in kami:make:migration', function () {
    Artisan::call('kami:make:migration', ['type' => 'linked']);

    expect(Artisan::output())->toContain('Nothing to do');
});
