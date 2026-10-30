<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Fields\Boolean;
use Psyao\Kami\Content\Fields\Date;
use Psyao\Kami\Content\Fields\DateTime;
use Psyao\Kami\Content\Fields\Email;
use Psyao\Kami\Content\Fields\Field;
use Psyao\Kami\Content\Fields\Number;
use Psyao\Kami\Content\Fields\Select;
use Psyao\Kami\Content\Fields\Text;
use Psyao\Kami\Content\Fields\Textarea;
use Psyao\Kami\Content\Fields\Url;
use Workbench\App\Content\Article;

// Each test migrates and rolls back for real, and DDL commits on MySQL: a transaction could not undo it.
uses(DatabaseMigrations::class);

/** A Field type no plugin registers a component for. */
final class Whisper extends Field {}

/** A type whose Fields each test declares. */
final class Note extends ContentType
{
    /** @var list<Field> */
    public static array $declared = [];

    public function fields(): array
    {
        return self::$declared;
    }
}

beforeEach(function () {
    $this->app->useDatabasePath($directory = sys_get_temp_dir() . '/kami-make-migration-' . uniqid());
    File::ensureDirectoryExists("{$directory}/migrations");
    config(['kami.types' => [Note::class]]);
    Note::$declared = [];
});

afterEach(fn () => File::deleteDirectory(database_path()));

/** @param list<Field> $fields */
function declareNote(array $fields): void
{
    Note::$declared = $fields;
}

/** The migration files written so far. */
function writtenMigrations(): array
{
    return File::glob(database_path('migrations/*.php'));
}

/** Runs the migrations written and not yet run. */
function migrateWritten(): void
{
    test()->artisan('migrate', ['--path' => database_path('migrations'), '--realpath' => true])->assertSuccessful();
}

/** @return array<string, string> Column name => Laravel type name. */
function noteColumns(): array
{
    return collect(Schema::getColumns('kami_type_note'))->mapWithKeys(fn (array $column): array => [$column['name'] => $column['type_name']])->all();
}

/** Kami's Rector set adds `: void` to a closure without one: a site's CI would fail on the file as written. */
function expectVoidSchemaClosures(string $migration): void
{
    expect(File::get($migration))->toContain('function (Blueprint $table): void {')->not->toContain('function (Blueprint $table) {');
}

it('writes a migration creating the table of a new type', function () {
    declareNote([Text::make('summary'), Textarea::make('body')]);

    $this->artisan('kami:make:migration')
        ->expectsOutputToContain('create_kami_type_note_table.php')
        ->assertSuccessful();

    migrateWritten();

    expect(array_keys(noteColumns()))->toBe(['entry_id', 'locale', 'summary', 'body'])
        ->and(noteColumns())->toMatchArray(['locale' => 'varchar', 'summary' => 'varchar', 'body' => 'text'])
        // MariaDB reports a nullable column's absent default as the string NULL.
        ->and(collect(Schema::getColumns('kami_type_note'))->every(fn (array $column): bool => in_array($column['default'], [null, 'NULL'], true)))->toBeTrue()
        ->and(collect(Schema::getIndexes('kami_type_note'))->contains(fn (array $index): bool => $index['unique'] && $index['columns'] === ['entry_id', 'locale']))->toBeTrue()
        ->and(collect(Schema::getForeignKeys('kami_type_note'))->first())->toMatchArray(['columns' => ['entry_id'], 'foreign_table' => 'kami_entries', 'on_delete' => 'cascade']);

    expectVoidSchemaClosures(writtenMigrations()[0]);
});

/** Writes the migration for the declared Fields and runs it. */
function generateAndMigrate(): void
{
    // Two migrations written in one second would share a file name.
    test()->travel(1)->seconds();
    test()->artisan('kami:make:migration')->assertSuccessful();
    migrateWritten();
}

/** Undoes the newest migration written. */
function rollBackLast(): void
{
    test()->artisan('migrate:rollback', ['--path' => database_path('migrations'), '--realpath' => true, '--step' => 1])->assertSuccessful();
}

it('adds, drops and retypes columns, and undoes it', function () {
    declareNote([Text::make('summary'), Text::make('body'), Text::make('obsolete')]);
    generateAndMigrate();
    $before = noteColumns();

    declareNote([Text::make('summary'), Textarea::make('body'), Text::make('author')]);
    $this->artisan('kami:make:migration')->expectsQuestion('kami_type_note.obsolete is no longer declared. Is it renamed?', '-')->assertSuccessful();
    migrateWritten();

    expect(noteColumns())->toMatchArray(['summary' => 'varchar', 'body' => 'text', 'author' => 'varchar'])->not->toHaveKey('obsolete');

    expectVoidSchemaClosures(writtenMigrations()[1]);

    rollBackLast();

    expect(noteColumns())->toEqualCanonicalizing($before);
});

it('renames a column when the terminal says so, and drops and adds it when not', function (string $answer, array $columns) {
    declareNote([Text::make('summary')]);
    generateAndMigrate();
    DB::table('kami_entries')->insert(['id' => $id = (string) Str::ulid(), 'site' => 'default', 'type' => 'note', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('kami_type_note')->insert(['entry_id' => $id, 'locale' => 'fr', 'summary' => 'Kept']);

    declareNote([Text::make('lede')]);
    $this->artisan('kami:make:migration')
        ->expectsChoice('kami_type_note.summary is no longer declared. Is it renamed?', $answer, ['lede' => 'Renamed to lede', '-' => 'Dropped, with its values'])
        ->assertSuccessful();
    migrateWritten();

    expect(array_keys(noteColumns()))->toBe(['entry_id', 'locale', 'lede'])
        ->and(DB::table('kami_type_note')->value('lede'))->toBe($columns['lede']);

    rollBackLast();

    expect(DB::table('kami_type_note')->value('summary'))->toBe($columns['summary']);
})->with([
    'renamed' => ['lede', ['lede' => 'Kept', 'summary' => 'Kept']],
    'dropped' => ['-', ['lede' => null, 'summary' => null]],
]);

it('refuses to guess a rename without a terminal, printing both options', function () {
    declareNote([Text::make('summary')]);
    generateAndMigrate();

    declareNote([Text::make('lede')]);
    $this->withoutMockingConsoleOutput();

    expect(Artisan::call('kami:make:migration', ['--no-interaction' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('kami_type_note loses summary and gains lede', 'renaming it to one of lede', 'dropping it')
        ->and(writtenMigrations())->toHaveCount(1);
});

it('says there is nothing to do when the tables match, and writes nothing', function () {
    declareNote([Text::make('summary')]);
    generateAndMigrate();

    $this->artisan('kami:make:migration')->expectsOutputToContain('Nothing to do')->assertSuccessful();

    expect(writtenMigrations())->toHaveCount(1);
});

it('gives no table to a type without a stored Field, and leaves an undeclared type\'s table alone', function () {
    declareNote([Text::make('summary')]);
    generateAndMigrate();

    config(['kami.types' => []]);
    $this->artisan('kami:make:migration')->expectsOutputToContain('Nothing to do')->assertSuccessful();

    config(['kami.types' => [Note::class]]);
    Schema::drop('kami_type_note');
    declareNote([]);
    $this->artisan('kami:make:migration')->expectsOutputToContain('Nothing to do')->assertSuccessful();
});

it('diffs only the type it is given, by handle or class', function (string $type) {
    config(['kami.types' => [Note::class, Article::class]]);
    Schema::drop('kami_type_article');
    declareNote([Text::make('summary')]);

    $this->artisan('kami:make:migration', ['type' => $type])->expectsOutputToContain('create_kami_type_note_table.php')->assertSuccessful();

    expect(File::get(writtenMigrations()[0]))->not->toContain('kami_type_article');
})->with(['note', Note::class]);

it('refuses an undeclared type', function () {
    $this->artisan('kami:make:migration', ['type' => 'nope'])->expectsOutputToContain('No Content Type in kami.types is called nope')->assertFailed();
});

it('refuses to run while a migration touching kami_type_* tables is pending', function () {
    declareNote([Text::make('summary')]);
    $this->artisan('kami:make:migration')->assertSuccessful();

    $this->artisan('kami:make:migration')->expectsOutputToContain('Run php artisan migrate first')->assertFailed();

    expect(writtenMigrations())->toHaveCount(1);
});

it('asks about each lost column when several are lost and gained, and retypes a renamed one', function () {
    declareNote([Text::make('summary'), Text::make('author')]);
    generateAndMigrate();

    declareNote([Textarea::make('lede'), Text::make('byline')]);
    $this->artisan('kami:make:migration')
        ->expectsChoice('kami_type_note.summary is no longer declared. Is it renamed?', 'lede', ['lede' => 'Renamed to lede', 'byline' => 'Renamed to byline', '-' => 'Dropped, with its values'])
        ->expectsChoice('kami_type_note.author is no longer declared. Is it renamed?', '-', ['byline' => 'Renamed to byline', '-' => 'Dropped, with its values'])
        ->assertSuccessful();
    migrateWritten();

    expect(array_keys(noteColumns()))->toEqualCanonicalizing(['entry_id', 'locale', 'lede', 'byline'])
        ->and(noteColumns())->toMatchArray(['lede' => 'text', 'byline' => 'varchar']);

    rollBackLast();

    expect(noteColumns())->toMatchArray(['summary' => 'varchar', 'author' => 'varchar'])->not->toHaveKeys(['lede', 'byline']);
});

it('refuses to drop or retype a column whose type it could not write back', function () {
    declareNote([Text::make('summary')]);
    generateAndMigrate();
    Schema::table('kami_type_note', fn (Blueprint $table) => $table->float('views')->nullable());

    $this->artisan('kami:make:migration')->expectsOutputToContain('kami_type_note.views has type')->assertFailed();

    expect(writtenMigrations())->toHaveCount(1);
});

it('says so when a lost column is dropped because no new one is left to rename it to', function () {
    declareNote([Text::make('summary'), Text::make('author')]);
    generateAndMigrate();

    declareNote([Text::make('lede')]);
    $this->artisan('kami:make:migration')
        ->expectsChoice('kami_type_note.summary is no longer declared. Is it renamed?', 'lede', ['lede' => 'Renamed to lede', '-' => 'Dropped, with its values'])
        ->expectsOutputToContain('kami_type_note.author is dropped, with its values')
        ->assertSuccessful();
});

it('creates a nullable Boolean column and finds it matching on every engine, then undoes it', function () {
    declareNote([Boolean::make('featured')]);
    generateAndMigrate();

    expect(collect(Schema::getColumns('kami_type_note'))->firstWhere('name', 'featured')['nullable'])->toBeTrue();

    $this->artisan('kami:make:migration')->expectsOutputToContain('Nothing to do')->assertSuccessful();

    expect(writtenMigrations())->toHaveCount(1);

    rollBackLast();

    expect(Schema::hasTable('kami_type_note'))->toBeFalse();
});

it('does not find a small integer column matching a Boolean', function () {
    declareNote([Text::make('summary')]);
    generateAndMigrate();
    Schema::table('kami_type_note', fn (Blueprint $table) => $table->tinyInteger('featured')->nullable());
    declareNote([Text::make('summary'), Boolean::make('featured')]);

    // SQLite reports `integer` for it, a retype it refuses; the others a type Kami cannot write back. Both say the same.
    $this->artisan('kami:make:migration')->expectsOutputToContain('by hand')->assertFailed();

    expect(writtenMigrations())->toHaveCount(1);
});

it('refuses a retype no cast answers on every engine, and the migration it prints converts the values and undoes it', function (Field $field, string $value) {
    declareNote([Text::make('amount')]);
    generateAndMigrate();
    DB::table('kami_entries')->insert(['id' => $id = (string) Str::ulid(), 'site' => 'default', 'type' => 'note', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('kami_type_note')->insert(['entry_id' => $id, 'locale' => '*', 'amount' => $value]);

    declareNote([$field]);
    $this->withoutMockingConsoleOutput();

    expect(Artisan::call('kami:make:migration', ['--no-interaction' => true]))->toBe(1)
        ->and($output = Artisan::output())->toContain('kami_type_note.amount', 'by hand')
        ->and(writtenMigrations())->toHaveCount(1);

    $this->travel(1)->seconds();
    File::put(database_path('migrations/' . now()->format('Y_m_d_His') . '_retype_amount.php'), mb_substr($output, mb_strpos($output, '<?php')));
    Artisan::call('migrate', ['--path' => database_path('migrations'), '--realpath' => true]);

    expect((string) DB::table('kami_type_note')->value('amount'))->toStartWith(rtrim($value, '0'))
        ->and(Artisan::call('kami:make:migration'))->toBe(0)
        ->and(Artisan::output())->toContain('Nothing to do');

    Artisan::call('migrate:rollback', ['--path' => database_path('migrations'), '--realpath' => true, '--step' => 1]);

    expect(noteColumns()['amount'])->toBe('varchar')
        ->and((string) DB::table('kami_type_note')->value('amount'))->toStartWith(rtrim($value, '0'));
})->with([
    'boolean' => [fn () => Boolean::make('amount'), '1'],
    'integer' => [fn () => Number::make('amount'), '12'],
    'decimal' => [fn () => Number::make('amount')->decimal(), '12.50'],
    'date' => [fn () => Date::make('amount'), '2026-01-02'],
    'dateTime' => [fn () => DateTime::make('amount'), '2026-01-02 03:04:05'],
]);

it('refuses a retype away from a column type just as it does towards one', function () {
    declareNote([Number::make('amount'), Boolean::make('featured')]);
    generateAndMigrate();

    declareNote([Text::make('amount'), Date::make('featured')]);

    $this->artisan('kami:make:migration')
        ->expectsOutputToContain('kami_type_note.amount')
        ->expectsOutputToContain('kami_type_note.featured')
        ->assertFailed();

    expect(writtenMigrations())->toHaveCount(1);
});

it('still retypes between string and text, both ways', function () {
    declareNote([Textarea::make('summary')]);
    generateAndMigrate();

    declareNote([Text::make('summary')]);
    generateAndMigrate();

    expect(noteColumns()['summary'])->toBe('varchar');

    rollBackLast();

    expect(noteColumns()['summary'])->toBe('text');
});

it('keeps working on a Field type with no registered component, so the declaration can be fixed', function () {
    declareNote([Whisper::make('rumour')]);

    generateAndMigrate();

    expect(noteColumns())->toHaveKey('rumour');
});

it('creates each column type and finds it matching on every engine, then undoes it', function () {
    declareNote([
        Url::make('website'), Email::make('contact'), Select::make('kind')->options(['a']),
        Number::make('pages'), Number::make('rating')->decimal(),
        Date::make('starts_on'), DateTime::make('starts_at'),
    ]);
    generateAndMigrate();

    expect(collect(Schema::getColumns('kami_type_note'))->except([0, 1])->every(fn (array $column): bool => $column['nullable']))->toBeTrue();

    $this->artisan('kami:make:migration')->expectsOutputToContain('Nothing to do')->assertSuccessful();

    rollBackLast();

    expect(Schema::hasTable('kami_type_note'))->toBeFalse();
});

it('adds a column of each new type to an existing table, and writes it back when dropped', function () {
    declareNote([Text::make('summary')]);
    generateAndMigrate();

    declareNote([Text::make('summary'), Number::make('pages'), Number::make('rating')->decimal(), Date::make('starts_on'), DateTime::make('starts_at')]);
    generateAndMigrate();

    expect(noteColumns())->toHaveKeys(['pages', 'rating', 'starts_on', 'starts_at']);

    declareNote([Text::make('summary')]);
    generateAndMigrate();
    rollBackLast();

    expect(noteColumns())->toHaveKeys(['pages', 'rating', 'starts_on', 'starts_at']);

    File::delete(last(writtenMigrations()));
    declareNote([Text::make('summary'), Number::make('pages'), Number::make('rating')->decimal(), Date::make('starts_on'), DateTime::make('starts_at')]);

    $this->artisan('kami:make:migration')->expectsOutputToContain('Nothing to do')->assertSuccessful();
});

/** @return list<string> The columns with an index of their own, the unique pair aside. */
function indexedNoteColumns(): array
{
    return collect(Schema::getIndexes('kami_type_note'))->filter(fn (array $index): bool => count($index['columns']) === 1 && ! $index['primary'])->pluck('columns.0')->sort()->values()->all();
}

it('indexes only a filterable or sortable Field, under a name every engine accepts', function () {
    declareNote([Text::make('summary'), Date::make('starts_on')->filterable(), Number::make(str_repeat('a', 40))->sortable()]);
    generateAndMigrate();

    expect(indexedNoteColumns())->toBe([str_repeat('a', 40), 'starts_on']);

    $this->artisan('kami:make:migration')->expectsOutputToContain('Nothing to do')->assertSuccessful();
});

it('indexes a column once it is declared filterable, drops the index once it is not, and undoes both', function () {
    declareNote([Text::make('kind')]);
    generateAndMigrate();

    declareNote([Text::make('kind')->filterable()]);
    generateAndMigrate();

    expect(indexedNoteColumns())->toBe(['kind']);

    rollBackLast();

    expect(indexedNoteColumns())->toBe([]);

    migrateWritten();
    declareNote([Text::make('kind')]);
    generateAndMigrate();

    expect(indexedNoteColumns())->toBe([]);

    rollBackLast();

    expect(indexedNoteColumns())->toBe(['kind']);
});

it('indexes a Field added to an existing table, and drops an indexed Field with its index, then undoes it', function () {
    declareNote([Text::make('summary')]);
    generateAndMigrate();

    declareNote([Text::make('summary'), Text::make('kind')->sortable()]);
    generateAndMigrate();

    expect(indexedNoteColumns())->toBe(['kind']);

    declareNote([Text::make('summary')]);
    generateAndMigrate();

    expect(noteColumns())->not->toHaveKey('kind');

    rollBackLast();

    expect(indexedNoteColumns())->toBe(['kind']);
});

it('keeps the index of a renamed column', function () {
    declareNote([Text::make('kind')->filterable()]);
    generateAndMigrate();

    declareNote([Text::make('genre')->filterable()]);
    $this->artisan('kami:make:migration')->expectsQuestion('kami_type_note.kind is no longer declared. Is it renamed?', 'genre')->assertSuccessful();
    migrateWritten();

    expect(indexedNoteColumns())->toBe(['genre']);

    $this->artisan('kami:make:migration')->expectsOutputToContain('Nothing to do')->assertSuccessful();
});

it('leaves an index the site wrote by hand alone, and does not add a second', function () {
    declareNote([Text::make('kind'), Text::make('genre')->filterable()]);
    Schema::create('kami_type_note', function (Blueprint $table): void {
        $table->string('kind')->nullable()->index('site_kind');
        $table->string('genre')->nullable()->index('site_genre');
    });
    Schema::table('kami_type_note', function (Blueprint $table): void {
        $table->string('entry_id')->nullable();
        $table->string('locale')->nullable();
    });

    $this->artisan('kami:make:migration')->expectsOutputToContain('Nothing to do')->assertSuccessful();
});

it('drops a hand-written index with its Field, so every engine drops the column, and writes it back', function (string $method, bool $unique) {
    declareNote([Text::make('summary'), Text::make('kind')]);
    generateAndMigrate();
    Schema::table('kami_type_note', fn (Blueprint $table) => $table->{$method}('kind', 'site_kind'));

    declareNote([Text::make('summary')]);
    generateAndMigrate();

    expect(noteColumns())->not->toHaveKey('kind');

    rollBackLast();

    expect(collect(Schema::getIndexes('kami_type_note'))->firstWhere('name', 'site_kind'))->toMatchArray(['columns' => ['kind'], 'unique' => $unique]);
})->with([
    'plain' => ['index', false],
    'unique' => ['unique', true],
]);

it('finds the Workbench\'s Article matching its migration, indexes included', function () {
    config(['kami.types' => [Article::class]]);

    $this->artisan('kami:make:migration')->expectsOutputToContain('Nothing to do')->assertSuccessful();
});
