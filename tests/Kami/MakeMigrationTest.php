<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Fields\Field;
use Psyao\Kami\Content\Fields\Text;
use Psyao\Kami\Content\Fields\Textarea;
use Workbench\App\Content\Article;

// Each test migrates and rolls back for real, and DDL commits on MySQL: a transaction could not undo it.
uses(DatabaseMigrations::class);

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

it('refuses to guess a rename from a process with no terminal, even without --no-interaction', function () {
    // A real process outside the testing environment, where Laravel treats every command as having a terminal.
    touch($database = database_path() . '/kami.sqlite');
    $artisan = Process::path(dirname(__DIR__, 2))->env(['APP_ENV' => 'local', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database]);
    $artisan->run(['php', 'vendor/bin/testbench', 'migrate', '--force'])->throw();
    (new PDO("sqlite:{$database}"))->exec('ALTER TABLE kami_type_label RENAME COLUMN colour TO legacy');

    $result = $artisan->run(['php', 'vendor/bin/testbench', 'kami:make:migration', 'label']);

    expect($result->exitCode())->toBe(1)
        ->and($result->output())->toContain('kami_type_label loses legacy and gains colour', 'renaming it to one of colour', 'dropping it');
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
    Schema::table('kami_type_note', fn (Blueprint $table) => $table->integer('views')->nullable());

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
