<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
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
use Psyao\Kami\Content\Fields\Group;
use Psyao\Kami\Content\Fields\Number;
use Psyao\Kami\Content\Fields\Repeater;
use Psyao\Kami\Content\Fields\Select;
use Psyao\Kami\Content\Fields\Text;
use Psyao\Kami\Content\Fields\Textarea;
use Psyao\Kami\Content\Fields\Url;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Workbench\App\Content\Article;

// Each test migrates and rolls back for real, and DDL commits on MySQL: a transaction could not undo it.
uses(DatabaseMigrations::class);

/** A Field type no plugin registers a component for. */
final class Whisper extends Field {}

/** A type with the longest handle ContentTypes allows. */
final class LongHandled extends ContentType
{
    public function handle(): string
    {
        return str_repeat('a', 40);
    }

    public function fields(): array
    {
        return [Text::make('summary')];
    }
}

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

it('writes a json column and finds nothing to do afterwards, on every engine', function () {
    declareNote([Repeater::make('links', [Text::make('label')]), Group::make('address', [Text::make('city')])]);
    generateAndMigrate();
    insertNoteRow(['links' => '[{"label":"Site"}]']);

    $this->artisan('kami:make:migration')->expectsOutputToContain('Nothing to do')->assertSuccessful();

    expect(File::get(writtenMigrations()[0]))->toContain("\$table->json('links')->nullable();", "\$table->json('address')->nullable();");
});

it('refuses a retype between a filled text column and a json one', function () {
    declareNote([Textarea::make('links')]);
    generateAndMigrate();
    insertNoteRow(['links' => 'Plain words']);

    declareNote([Repeater::make('links', [Text::make('label')])]);
    $this->withoutMockingConsoleOutput();

    expect(Artisan::call('kami:make:migration', ['--no-interaction' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('no cast works on every engine')
        ->and(writtenMigrations())->toHaveCount(1);
});

it('refuses a retype away from a json column', function () {
    declareNote([Repeater::make('links', [Text::make('label')])]);
    generateAndMigrate();

    declareNote([Text::make('links')]);
    $this->withoutMockingConsoleOutput();

    expect(Artisan::call('kami:make:migration', ['--no-interaction' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('no cast works on every engine');
})->skip(fn (): bool => DB::getDriverName() === 'sqlite', 'SQLite reports a json column as text, which a string column may change to.');

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

/** Inserts one Entry's row. */
function insertNoteRow(array $values): void
{
    DB::table('kami_entries')->insert(['id' => $id = (string) Str::ulid(), 'site' => 'default', 'type' => 'note', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('kami_type_note')->insert(['entry_id' => $id, 'locale' => '*', ...$values]);
}

/** Saves the migration a refusal printed, and runs it. */
function migratePrinted(string $output): void
{
    test()->travel(1)->seconds();
    File::put(database_path('migrations/' . now()->format('Y_m_d_His') . '_retype.php'), mb_substr($output, mb_strpos($output, '<?php')));
    Artisan::call('migrate', ['--path' => database_path('migrations'), '--realpath' => true]);
}

/** Runs the command, its output kept, with these lines typed in a terminal. */
function makeMigrationAnswering(string $answers): array
{
    $input = new ArrayInput(['command' => 'kami:make:migration']);
    $input->setStream($stream = fopen('php://memory', 'r+'));
    fwrite($stream, $answers);
    rewind($stream);

    return [Artisan::handle($input, $output = new BufferedOutput), $output->fetch()];
}

it('refuses a retype no cast answers on every engine, and the migration it prints converts the values and undoes it', function (Field $from, Field $to, string $value) {
    declareNote([$from]);
    generateAndMigrate();
    insertNoteRow(['amount' => $value]);
    $before = noteColumns()['amount'];

    declareNote([$to]);
    $this->withoutMockingConsoleOutput();

    expect(Artisan::call('kami:make:migration', ['--no-interaction' => true]))->toBe(1)
        ->and($output = Artisan::output())->toContain('kami_type_note.amount', 'by hand', 'run the command again')
        ->and(writtenMigrations())->toHaveCount(1);

    migratePrinted($output);

    expect((string) DB::table('kami_type_note')->value('amount'))->toStartWith(rtrim($value, '0'))
        ->and(Artisan::call('kami:make:migration'))->toBe(0)
        ->and(Artisan::output())->toContain('Nothing to do');

    Artisan::call('migrate:rollback', ['--path' => database_path('migrations'), '--realpath' => true, '--step' => 1]);

    expect(noteColumns()['amount'])->toBe($before)
        ->and((string) DB::table('kami_type_note')->value('amount'))->toStartWith(rtrim($value, '0'));
})->with([
    'string to boolean' => [fn () => Text::make('amount'), fn () => Boolean::make('amount'), '1'],
    'string to integer' => [fn () => Text::make('amount'), fn () => Number::make('amount'), '12'],
    'string to decimal' => [fn () => Text::make('amount'), fn () => Number::make('amount')->decimal(), '12.50'],
    'string to date' => [fn () => Text::make('amount'), fn () => Date::make('amount'), '2026-01-02'],
    'string to dateTime' => [fn () => Text::make('amount'), fn () => DateTime::make('amount'), '2026-01-02 03:04:05'],
    'integer to string' => [fn () => Number::make('amount'), fn () => Text::make('amount'), '12'],
]);

it('prints a rename before the retype relying on it, and undoes both', function () {
    declareNote([Text::make('total')]);
    generateAndMigrate();
    insertNoteRow(['total' => '12']);

    declareNote([Number::make('amount')]);
    [$status, $output] = makeMigrationAnswering("amount\n");

    expect($status)->toBe(1);

    migratePrinted($output);

    expect(noteColumns())->toHaveKey('amount')->not->toHaveKey('total')
        ->and((string) DB::table('kami_type_note')->value('amount'))->toBe('12');

    Artisan::call('migrate:rollback', ['--path' => database_path('migrations'), '--realpath' => true, '--step' => 1]);

    expect(noteColumns()['total'])->toBe('varchar')->and(noteColumns())->not->toHaveKey('amount')
        ->and((string) DB::table('kami_type_note')->value('total'))->toBe('12');
});

it('moves an index with a refused retype, so every engine drops the column, and puts it back', function (string $method, bool $unique) {
    declareNote([Text::make('amount')]);
    generateAndMigrate();
    Schema::table('kami_type_note', fn (Blueprint $table) => $table->{$method}('amount', 'site_amount'));

    declareNote([Number::make('amount')]);
    $this->withoutMockingConsoleOutput();
    Artisan::call('kami:make:migration', ['--no-interaction' => true]);
    migratePrinted(Artisan::output());

    expect(noteColumns())->toHaveKey('amount')
        ->and(collect(Schema::getIndexes('kami_type_note'))->firstWhere('name', 'site_amount'))->toMatchArray(['columns' => ['amount'], 'unique' => $unique]);

    Artisan::call('migrate:rollback', ['--path' => database_path('migrations'), '--realpath' => true, '--step' => 1]);

    expect(collect(Schema::getIndexes('kami_type_note'))->firstWhere('name', 'site_amount'))->toMatchArray(['columns' => ['amount'], 'unique' => $unique]);
})->with([
    'plain' => ['index', false],
    'unique' => ['unique', true],
]);

it('stops on a value too long for a string, or keeps it whole on SQLite', function () {
    declareNote([Textarea::make('summary')]);
    generateAndMigrate();
    insertNoteRow(['summary' => $long = str_repeat('a', 300)]);

    declareNote([Text::make('summary')]);
    $this->travel(1)->seconds();
    $this->artisan('kami:make:migration')->assertSuccessful();

    if (DB::getDriverName() === 'sqlite') {
        migrateWritten();
    } else {
        expect(fn () => Artisan::call('migrate', ['--path' => database_path('migrations'), '--realpath' => true]))->toThrow(QueryException::class);
    }

    expect(DB::table('kami_type_note')->value('summary'))->toBe($long);
});

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

it('names the unique index and the foreign key of the longest handle within every engine\'s limit, and undoes it', function () {
    config(['kami.types' => [LongHandled::class]]);
    $table = 'kami_type_' . str_repeat('a', 40);

    generateAndMigrate();

    $unique = collect(Schema::getIndexes($table))->firstWhere('columns', ['entry_id', 'locale']);
    $foreign = collect(Schema::getForeignKeys($table))->first();

    expect($unique['unique'])->toBeTrue()
        ->and($unique['name'])->toStartWith('kami_idx_')
        ->and($foreign)->toMatchArray(['columns' => ['entry_id'], 'foreign_table' => 'kami_entries', 'on_delete' => 'cascade']);

    // SQLite does not name a foreign key.
    if ($foreign['name'] !== null) {
        expect($foreign['name'])->toStartWith('kami_fk_');
    }

    // Whatever index an engine keeps for the foreign key is not taken for a Field's.
    $this->artisan('kami:make:migration')->expectsOutputToContain('Nothing to do')->assertSuccessful();

    rollBackLast();

    expect(Schema::hasTable($table))->toBeFalse();
});

it('gives two tables different names for their unique index and foreign key', function () {
    declareNote([Text::make('summary')]);
    config(['kami.types' => [Note::class, LongHandled::class]]);

    generateAndMigrate();

    $tables = ['kami_type_note', 'kami_type_' . str_repeat('a', 40)];
    $names = collect($tables)->flatMap(fn (string $table): array => [
        collect(Schema::getIndexes($table))->firstWhere('columns', ['entry_id', 'locale'])['name'],
        collect(Schema::getForeignKeys($table))->first()['name'],
    ]);

    expect($names->unique())->toHaveCount($names->count());
})->skip(fn (): bool => DB::getDriverName() === 'sqlite', 'SQLite does not name a foreign key.');

it('leaves a table generated under Laravel\'s own names alone, and still changes it', function () {
    Schema::create('kami_type_note', function (Blueprint $table): void {
        $table->foreignUlid('entry_id')->constrained('kami_entries')->cascadeOnDelete();
        $table->string('locale', 20);
        $table->string('summary')->nullable();
        $table->unique(['entry_id', 'locale']);
    });
    $names = fn (): array => collect([...Schema::getIndexes('kami_type_note'), ...Schema::getForeignKeys('kami_type_note')])->pluck('name')->sort()->values()->all();
    $before = $names();

    declareNote([Text::make('summary')]);
    $this->artisan('kami:make:migration')->expectsOutputToContain('Nothing to do')->assertSuccessful();

    declareNote([Text::make('summary'), Date::make('starts_on')]);
    generateAndMigrate();

    expect(noteColumns())->toHaveKey('starts_on')
        ->and($names())->toBe($before);
});

it('finds the Workbench\'s Article matching its migration, indexes included', function () {
    config(['kami.types' => [Article::class]]);

    $this->artisan('kami:make:migration')->expectsOutputToContain('Nothing to do')->assertSuccessful();
});
