<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Fields\Boolean;
use Psyao\Kami\Content\Fields\Date;
use Psyao\Kami\Content\Fields\Field;
use Psyao\Kami\Content\Fields\Number;
use Psyao\Kami\Content\Fields\Select;
use Psyao\Kami\Content\Fields\Text;
use Psyao\Kami\Content\Section;
use Psyao\Kami\Content\Tab;
use Workbench\App\Content\Project;

uses(RefreshDatabase::class);

/** A type whose Fields and layout each test declares. */
final class Arranged extends ContentType
{
    /** @var array<mixed> */
    public static array $declared = [];

    public function fields(): array
    {
        return self::$declared; // @phpstan-ignore return.type
    }
}

/** A Field shown on a given day: reading its target casts it to a date. */
final class Dated extends ContentType
{
    public function fields(): array
    {
        return [Date::make('day'), Text::make('note')->visibleWhen('day', '2026-10-30')];
    }
}

/** @param array<string, mixed> $values */
function saveProject(array $values, ?Entry $entry = null, ?string $locale = null): Entry
{
    return resolve(SaveEntry::class)->handle(Project::class, $values, $entry, $locale);
}

/** @return array<string, mixed> The Entry's stored row in that Locale, or `'*'`. */
function projectRow(Entry $entry, string $locale): array
{
    return (array) DB::table('kami_type_project')->where(['entry_id' => $entry->id, 'locale' => $locale])->first();
}

beforeEach(function () {
    Arranged::$declared = [];
});

it('reads the Fields inside tabs and sections as if they were flat', function () {
    expect(array_map(fn (Field $field): string => $field->handle, (new Project)->flatFields()))
        ->toBe(['client', 'featured', 'featured_note', 'kind', 'source', 'story', 'launched_on', 'links', 'address', 'related', 'tags']);

    $entry = saveProject(['title' => 'Kami', 'client' => 'Ada', 'launched_on' => '2026-10-30']);

    expect($entry->client)->toBe('Ada')
        ->and($entry->launched_on->format('Y-m-d'))->toBe('2026-10-30');
});

it('fails on an invalid layout or condition, naming the class and the handle', function (array $declared, string $message) {
    Arranged::$declared = $declared;
    config(['kami.types' => [Arranged::class]]);

    expect(fn () => $this->artisan('kami:doctor')->run())->toThrow(InvalidArgumentException::class, $message);
})->with([
    'handle duplicated across layout' => [[Tab::make('one', [Text::make('note')]), Section::make('two', [Text::make('note')])], 'Arranged: the Field handle "note" appears twice'],
    'layout handle not snake_case' => [[Tab::make('Main', [])], 'Arranged: the layout handle "Main" is not snake_case'],
    'layout handle duplicated' => [[Tab::make('main', []), Section::make('main', [])], 'Arranged: the layout handle "main" appears twice'],
    'tab inside a section' => [[Section::make('main', [Tab::make('inner', [])])], 'Arranged: the Tab "inner" cannot sit inside a layout'],
    'tab inside a tab' => [[Tab::make('main', [Tab::make('inner', [])])], 'Arranged: the Tab "inner" cannot sit inside a layout'],
    'section inside a section' => [[Section::make('main', [Section::make('inner', [])])], 'Arranged: the Section "inner" sits at the top level or inside a Tab'],
    'undeclared target' => [[Text::make('note')->visibleWhen('nope', true)], 'Arranged: the Field "note" is shown according to "nope", which the type does not declare'],
    'filled, undeclared target' => [[Text::make('note')->visibleWhenFilled('nope')], 'Arranged: the Field "note" is shown according to "nope"'],
    'itself as target' => [[Text::make('note')->visibleWhenFilled('note')], 'Arranged: the Field "note" is shown according to "note", which is itself conditional'],
    'conditional target' => [[Boolean::make('active'), Text::make('a')->visibleWhen('active', true), Text::make('b')->visibleWhenFilled('a')], 'Arranged: the Field "b" is shown according to "a", which is itself conditional'],
    'Translatable target' => [[Text::make('lead'), Text::make('note')->visibleWhenFilled('lead')], 'Arranged: the Field "note" is shown according to the Translatable "lead"'],
    'filled Boolean target' => [[Boolean::make('active'), Text::make('note')->visibleWhenFilled('active')], 'Arranged: the Field "note" is shown when the Boolean "active" is filled'],
    'value no option of the Select' => [[Select::make('kind')->options(['news']), Text::make('note')->visibleWhen('kind', 'newz')], 'Arranged: the Field "note" is shown for a value that is not an option of the Select "kind"'],
]);

it('accepts a target declared after the Field, in another tab', function () {
    Arranged::$declared = [Tab::make('one', [Text::make('note')->visibleWhen('active', true)]), Tab::make('two', [Section::make('inner', [Boolean::make('active')])])];
    config(['kami.types' => [Arranged::class]]);

    expect(fn () => $this->artisan('kami:doctor')->run())->not->toThrow(InvalidArgumentException::class);
});

it('reads tab and section titles from kami-types by handle, or humanises the handle', function () {
    expect((new Project)->tabLabel('details'))->toBe('Details')
        ->and((new Project)->sectionLabel('highlight'))->toBe('Highlight')
        ->and((new Project)->tabLabel('long_story'))->toBe('Long Story')
        ->and((new Project)->sectionLabel('long_story'))->toBe('Long Story');
});

it('warns about each missing tab and section title', function (array $declared, string $key) {
    Arranged::$declared = $declared;
    config(['kami.types' => [Arranged::class]]);

    $this->artisan('kami:doctor')->expectsOutputToContain($key);
})->with([
    'tab' => [[Tab::make('main', [])], 'arranged.tabs.main.label'],
    'section' => [[Section::make('facts', [])], 'arranged.sections.facts.label'],
    'section inside a tab' => [[Tab::make('a', [Section::make('b', [])])], 'arranged.sections.b.label'],
]);

it('neither validates nor stores a hidden Field, whoever gave it', function () {
    $entry = saveProject(['title' => 'Kami', 'kind' => 'news', 'source' => 'not a url']);

    expect($entry->source)->toBeNull()
        ->and(projectRow($entry, '*')['source'])->toBeNull();
});

it('validates and stores a Field once it shows, equality or membership', function () {
    $entry = saveProject(['title' => 'Kami', 'kind' => 'essay', 'source' => 'https://example.test']);

    expect($entry->source)->toBe('https://example.test')
        ->and(fn () => saveProject(['source' => 'not a url'], $entry))->toThrow(ValidationException::class);
});

it('does not require a hidden Field to publish, and requires it once shown', function () {
    $published = saveProject(['title' => 'Kami', 'status' => 'published', 'featured' => false]);

    expect($published->status)->toBe('published')
        ->and(fn () => saveProject(['featured' => true], $published))->toThrow(ValidationException::class);
});

it('clears a stored value when its Field becomes hidden, with no value given for it', function () {
    $entry = saveProject(['title' => 'Kami', 'kind' => 'review', 'source' => 'https://example.test']);

    expect(saveProject(['kind' => 'news'], $entry)->source)->toBeNull()
        ->and(projectRow($entry, '*')['source'])->toBeNull();
});

it('does not store the default of a hidden Field', function () {
    expect(saveProject(['title' => 'Kami', 'kind' => 'news'])->source)->toBeNull();
});

it('reads a condition on what is stored when the save does not give its target', function () {
    $entry = saveProject(['title' => 'Kami', 'featured' => true, 'featured_note' => 'Why']);

    expect(saveProject(['title' => 'Kami 2', 'featured_note' => 'Because'], $entry)->featured_note)->toBe('Because');
});

it('clears a Translatable Field in every Locale when its Shared target hides it', function () {
    $entry = saveProject(['title' => 'Kami', 'featured' => true, 'featured_note' => 'Pourquoi'], locale: 'fr');
    saveProject(['title' => 'Kami', 'featured_note' => 'Why'], $entry, 'en');

    saveProject(['title' => 'Kami', 'featured' => false], $entry, 'fr');

    expect(projectRow($entry, 'fr')['featured_note'])->toBeNull()
        ->and(projectRow($entry, 'en')['featured_note'])->toBeNull();
});

it('clears them from a Shared-only write too', function () {
    $entry = saveProject(['title' => 'Kami', 'featured' => true, 'featured_note' => 'Pourquoi'], locale: 'fr');

    saveProject(['featured' => false], $entry, '*');

    expect(projectRow($entry, 'fr')['featured_note'])->toBeNull();
});

it('clears nothing stored when the save does not give the target', function () {
    $entry = saveProject(['title' => 'Kami', 'featured' => true, 'featured_note' => 'Why']);
    DB::table('kami_type_project')->where(['entry_id' => $entry->id, 'locale' => '*'])->update(['featured' => false]);

    saveProject(['title' => 'Kami 2'], $entry);

    expect(projectRow($entry, 'fr')['featured_note'])->toBe('Why');
});

it('keeps a given value in a Locale whose own target row is empty: a target is Shared', function () {
    $entry = saveProject(['title' => 'Kami', 'client' => 'Ada', 'story' => 'Il était une fois'], locale: 'fr');

    expect(saveProject(['title' => 'Kami', 'story' => 'Once upon a time'], $entry, 'en')->story)->toBe('Il était une fois')
        ->and(projectRow($entry, 'en')['story'])->toBe('Once upon a time');
});

it('reports an invalid target before reading a condition on it', function () {
    config(['kami.types' => [Dated::class]]);

    expect(fn () => resolve(SaveEntry::class)->handle(Dated::class, ['day' => 'soon']))->toThrow(ValidationException::class);
});

it('compares through the target\'s cast, so stored, typed and declared values agree', function (Field $target, mixed $declared, mixed $given, bool $visible) {
    expect(Text::make('note')->visibleWhen($target->handle, $declared)->isVisibleGiven($target, $given))->toBe($visible);
})->with([
    'stored true' => [Boolean::make('active'), true, 1, true],
    'typed false' => [Boolean::make('active'), true, false, false],
    'stored false' => [Boolean::make('active'), false, 0, true],
    'absent reads as an untouched toggle' => [Boolean::make('active'), false, null, true],
    'absent is not true' => [Boolean::make('active'), true, null, false],
    'typed number' => [Number::make('n'), 5, '5', true],
    'stored decimal' => [Number::make('n')->decimal(), '12.50', 12.5, true],
    'a stored day' => [Date::make('d'), '2026-10-30', '2026-10-30T00:00:00Z', true],
    'numeric strings stay strings' => [Text::make('t'), '1', '01', false],
    'membership' => [Text::make('t'), ['a', 'b'], 'b', true],
    'out of the list' => [Text::make('t'), ['a', 'b'], 'c', false],
]);

it('counts a value as filled and a blank as not', function (mixed $given, bool $visible) {
    expect(Text::make('note')->visibleWhenFilled('lead')->isVisibleGiven(Text::make('lead'), $given))->toBe($visible);
})->with([['Ada', true], [0, true], [null, false], ['', false]]);
