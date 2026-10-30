<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Fields\Date;
use Psyao\Kami\Content\Fields\Group;
use Psyao\Kami\Content\Fields\Number;
use Psyao\Kami\Content\Fields\Repeater;
use Psyao\Kami\Content\Fields\Select;
use Psyao\Kami\Content\Fields\Text;
use Psyao\Kami\Content\Fields\Url;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Content\Section;
use Workbench\App\Content\Project;

uses(RefreshDatabase::class);

/** A type whose structured Fields each declaration test sets. */
final class Rowed extends ContentType
{
    /** @var array<mixed> */
    public static array $declared = [];

    public function fields(): array
    {
        return self::$declared; // @phpstan-ignore return.type
    }
}

/** @param array<string, mixed> $values */
function saveLinks(array $values, ?Entry $entry = null, ?string $locale = null): Entry
{
    return resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Kami', ...$values], $entry, $locale ?? 'fr');
}

/** @return array<string, list<string>> */
function linkErrors(array $values, ?Entry $entry = null): array
{
    try {
        saveLinks($values, $entry);
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

function storedColumn(Entry $entry, string $column): mixed
{
    return DB::table('kami_type_project')->where(['entry_id' => $entry->id, 'locale' => '*'])->value($column);
}

function refused(array $fields): void
{
    Rowed::$declared = $fields;
    config(['kami.types' => [Rowed::class]]);
    resolve(ContentTypes::class)->all();
}

/** MySQL and MariaDB commit a schema change, so the table can outlive the test before it. */
function structuredTable(Closure $columns): void
{
    Schema::dropIfExists('kami_type_rowed');
    Schema::create('kami_type_rowed', function (Blueprint $table) use ($columns): void {
        $table->ulid('entry_id');
        $table->string('locale', 20);
        $columns($table);
    });
}

it('stores a Repeater and a Group as one Shared json column, read back decoded', function () {
    $entry = saveLinks([
        'links' => [['label' => 'Site', 'url' => 'https://example.test'], ['label' => 'Été', 'url' => 'https://example.test/ete']],
        'address' => ['street' => 'Rue du Lac', 'city' => 'Vevey'],
    ]);

    expect($entry->links)->toBe([['label' => 'Site', 'url' => 'https://example.test'], ['label' => 'Été', 'url' => 'https://example.test/ete']])
        ->and($entry->address)->toBe(['street' => 'Rue du Lac', 'city' => 'Vevey'])
        ->and(json_decode((string) storedColumn($entry, 'address'), true))->toBe(['street' => 'Rue du Lac', 'city' => 'Vevey']);
});

it('keeps a stored value when it is left out, and stores null for a blank one', function () {
    $entry = saveLinks(['links' => [['label' => 'Site', 'url' => null]], 'address' => ['street' => 'Rue du Lac', 'city' => '']]);

    expect($entry->address)->toBe(['street' => 'Rue du Lac', 'city' => null]);

    $kept = saveLinks(['client' => 'Ada'], $entry);

    expect($kept->links)->toBe([['label' => 'Site', 'url' => null]]);

    $blank = saveLinks(['links' => [], 'address' => ['street' => '', 'city' => null]], $kept);

    expect(storedColumn($blank, 'links'))->toBeNull()
        ->and(storedColumn($blank, 'address'))->toBeNull()
        ->and($blank->links)->toBeNull();
});

it('applies a sub-Field\'s required and rules row by row, with the error on that row', function () {
    expect(linkErrors(['links' => [['label' => 'Good', 'url' => 'https://example.test'], ['label' => 'Bad', 'url' => 'nope']]]))
        ->toHaveKey('links.1.url')->not->toHaveKey('links.0.url');

    // Required to publish, as at the top level: a Draft may leave it blank.
    expect(linkErrors(['links' => [['label' => 'No address']]]))->toBe([])
        ->and(linkErrors(['status' => 'published', 'featured_note' => 'x', 'links' => [['label' => 'No address'], ['url' => 'https://example.test']]]))
        ->toHaveKey('links.0.url')->not->toHaveKey('links.1.url');

    expect(linkErrors(['address' => ['street' => str_repeat('a', 256)]]))->toHaveKey('address.street');
});

it('validates a stored value decoded when publishing leaves it out', function () {
    $entry = saveLinks(['links' => [['label' => 'No address']]]);

    expect(linkErrors(['status' => 'published'], $entry))->toHaveKey('links.0.url');
});

it('refuses a value of the wrong shape, and stores only declared sub-Fields', function () {
    expect(linkErrors(['links' => 'nope']))->toHaveKey('links')
        ->and(linkErrors(['links' => ['a' => ['label' => 'x']]]))->toHaveKey('links')
        ->and(linkErrors(['links' => ['nope']]))->toHaveKey('links.0')
        ->and(linkErrors(['address' => 'nope']))->toHaveKey('address');

    $entry = saveLinks(['links' => [['label' => 'x', 'admin' => true]], 'address' => ['city' => 'Vevey', 'admin' => true]]);

    expect($entry->links)->toBe([['label' => 'x', 'url' => null]])
        ->and($entry->address)->toBe(['street' => null, 'city' => 'Vevey']);
});

it('reads and writes each sub-Field through its own type, nested ones included', function () {
    Rowed::$declared = [Repeater::make('stops', [
        Date::make('day'),
        Number::make('seats'),
        Group::make('place', [Text::make('city')->required()]),
        Repeater::make('notes', [Text::make('text')]),
    ])];
    config(['kami.types' => [Rowed::class]]);
    structuredTable(fn (Blueprint $table) => $table->json('stops')->nullable());

    $save = resolve(SaveEntry::class);
    $entry = $save->handle(Rowed::class, ['title' => 'x', 'stops' => [['day' => '2026-10-30', 'seats' => '3', 'place' => ['city' => 'Vevey'], 'notes' => [['text' => 'a']]]]], locale: 'fr');

    expect($entry->stops[0]['day'])->toBeInstanceOf(CarbonImmutable::class)
        ->and($entry->stops[0]['day']->format('Y-m-d'))->toBe('2026-10-30')
        ->and($entry->stops[0]['seats'])->toBe(3)
        ->and($entry->stops[0]['place'])->toBe(['city' => 'Vevey'])
        ->and($entry->stops[0]['notes'])->toBe([['text' => 'a']]);

    expect(fn () => $save->handle(Rowed::class, ['title' => 'x', 'status' => 'published', 'stops' => [['place' => ['city' => '']]]], locale: 'fr'))
        ->toThrow(ValidationException::class, 'stops.0.place.city');
});

it('requires a Group\'s sub-Field to publish, and validates its nested rows', function () {
    Rowed::$declared = [Group::make('place', [Text::make('city')->required(), Repeater::make('stops', [Url::make('url')])])];
    config(['kami.types' => [Rowed::class]]);
    structuredTable(fn (Blueprint $table) => $table->json('place')->nullable());
    $save = resolve(SaveEntry::class);

    expect(fn () => $save->handle(Rowed::class, ['title' => 'x', 'status' => 'published'], locale: 'fr'))->toThrow(ValidationException::class, 'place.city')
        ->and(fn () => $save->handle(Rowed::class, ['title' => 'x', 'place' => ['stops' => [['url' => 'nope']]]], locale: 'fr'))->toThrow(ValidationException::class, 'place.stops.0.url');
});

it('clears a hidden Repeater and drops its rows\' rules', function () {
    Rowed::$declared = [Text::make('kind')->shared(), Repeater::make('stops', [Url::make('url')])->visibleWhen('kind', 'tour')];
    config(['kami.types' => [Rowed::class]]);
    structuredTable(function (Blueprint $table): void {
        $table->string('kind')->nullable();
        $table->json('stops')->nullable();
    });

    $entry = resolve(SaveEntry::class)->handle(Rowed::class, ['title' => 'x', 'kind' => 'walk', 'stops' => [['url' => 'nope']]], locale: 'fr');

    expect($entry->stops)->toBeNull();
});

it('refuses a declaration a Repeater or Group cannot honour', function (array $fields, string $message) {
    expect(fn () => refused($fields))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'a layout' => [fn () => [Repeater::make('rows', [Section::make('box', [Text::make('a')])])], 'cannot hold'],
    'a layout in a Group' => [fn () => [Group::make('rows', [Section::make('box', [Text::make('a')])])], 'cannot hold'],
    'nested deeper' => [fn () => [Repeater::make('rows', [Group::make('inner', [Section::make('box', [])])])], 'cannot hold'],
    'no sub-Field' => [fn () => [Repeater::make('rows')], 'no sub-Field'],
    'a bad handle' => [fn () => [Group::make('rows', [Text::make('Bad')])], 'not snake_case'],
    'a duplicate' => [fn () => [Group::make('rows', [Text::make('a'), Text::make('a')])], 'appears twice'],
    'a condition' => [fn () => [Group::make('rows', [Text::make('a'), Text::make('b')->visibleWhenFilled('a')])], 'condition'],
    'an index' => [fn () => [Group::make('rows', [Text::make('a')->sortable()])], 'filterable or sortable'],
    'a default' => [fn () => [Group::make('rows', [Text::make('a')->default('x')])], 'default'],
    'a Select with no option' => [fn () => [Group::make('rows', [Select::make('a')])], 'no options'],
    'an index on the column' => [fn () => [Repeater::make('rows', [Text::make('a')])->filterable()], 'filterable or sortable'],
    'as a condition target' => [fn () => [Repeater::make('rows', [Text::make('a')]), Text::make('b')->visibleWhenFilled('rows')], 'Repeater or Group'],
]);

it('asks for a sub-Field\'s label and options in the doctor\'s label check', function () {
    Rowed::$declared = [Group::make('place', [Select::make('kind')->options(['town'])])];
    config(['kami.types' => [Rowed::class]]);
    $this->withoutMockingConsoleOutput();
    Artisan::call('kami:doctor', ['--no-ansi' => true]);

    expect(preg_replace('/\s+/', '', Artisan::output()))->toContain('rowed.fields.place.fields.kind.label', 'rowed.fields.place.fields.kind.options.town');
});
