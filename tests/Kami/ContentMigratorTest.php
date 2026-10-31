<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Fields\Field;
use Psyao\Kami\Content\Fields\Repeater;
use Psyao\Kami\Content\Fields\Text;

// Each test migrates for real, and DDL commits on MySQL: a transaction could not undo it.
uses(DatabaseMigrations::class);

/** A type whose handle and Fields each test declares. */
final class Memo extends ContentType
{
    public static string $name = 'memo';

    /** @var list<Field> */
    public static array $declared = [];

    public function handle(): string
    {
        return self::$name;
    }

    public function fields(): array
    {
        return self::$declared;
    }
}

beforeEach(function () {
    $this->app->useDatabasePath($directory = sys_get_temp_dir() . '/kami-content-migrator-' . uniqid());
    File::ensureDirectoryExists("{$directory}/migrations");
    config(['kami.types' => [Memo::class], 'kami.sites.default.locales' => ['fr', 'en']]);
    Memo::$name = 'memo';
    Memo::$declared = [];
    $this->migrations = 0;

    /** Runs a statement of `up()` as a site's migration does. */
    $this->inMigration = function (string $statement): void {
        File::put(database_path('migrations/2100_01_01_' . str_pad((string) ++$this->migrations, 6, '0', STR_PAD_LEFT) . '_content.php'), <<<PHP
            <?php

            use Illuminate\\Database\\Migrations\\Migration;
            use Psyao\\Kami\\Schema\\ContentMigrator;

            return new class extends Migration
            {
                public function up(): void
                {
                    {$statement}
                }
            };
            PHP);

        Artisan::call('migrate', ['--force' => true]);
    };

    /** The type's table as `kami:make:migration` writes it. */
    $this->declare = function (Field ...$fields): void {
        Memo::$declared = array_values($fields);
        // The command refuses to write a second file in the same second.
        $this->travel(1)->seconds();
        Artisan::call('kami:make:migration', ['--no-interaction' => true]);
        Artisan::call('migrate', ['--force' => true]);
    };
});

afterEach(fn () => File::deleteDirectory(database_path()));

function memo(string $type = 'memo'): string
{
    $id = (string) Str::ulid();
    DB::table('kami_entries')->insert(['id' => $id, 'site' => 'default', 'type' => $type]);

    return $id;
}

/** @param  array<string, mixed>  $values */
function translated(string $entry, string $locale, array $values = []): void
{
    DB::table('kami_translations')->insert(['id' => (string) Str::ulid(), 'entry_id' => $entry, 'locale' => $locale, 'title' => "Title {$locale}", 'slug' => "slug-{$locale}", 'status' => 'published', 'updated_at' => '2020-01-01 00:00:00', ...$values]);
}

/** @param  array<string, mixed>  $values */
function memoRow(string $entry, string $locale, array $values, string $table = 'kami_type_memo'): void
{
    DB::table($table)->insert(['entry_id' => $entry, 'locale' => $locale, ...$values]);
}

function linked(string $source, string $field, ?string $locale, string $target, int $position = 0): void
{
    DB::table('kami_references')->insert(['id' => (string) Str::ulid(), 'source_entry_id' => $source, 'field' => $field, 'locale' => $locale, 'target_entry_id' => $target, 'position' => $position]);
}

/** @return array<string, mixed> One column of a type's rows, by Locale, leaving out nulls. */
function column(string $entry, string $field, string $table = 'kami_type_memo'): array
{
    return DB::table($table)->where('entry_id', $entry)->whereNotNull($field)->pluck($field, 'locale')->sortKeys()->all();
}

/** @return array<string, list<string>> A Field's targets, by Locale (`'*'` when shared), in pick order. */
function targets(string $entry, string $field): array
{
    $targets = [];

    foreach (DB::table('kami_references')->where(['source_entry_id' => $entry, 'field' => $field])->orderBy('position')->get() as $row) {
        $targets[$row->locale ?? '*'][] = $row->target_entry_id;
    }

    ksort($targets);

    return $targets;
}

describe('renameType', function () {
    it('moves the Entries and the table, and frees every name of the old handle for a new type', function () {
        ($this->declare)(Text::make('summary')->filterable());
        memoRow($entry = memo(), 'fr', ['summary' => 'Bonjour']);
        memo('other');

        ($this->inMigration)("ContentMigrator::renameType('memo', 'note');");

        expect(DB::table('kami_entries')->where('id', $entry)->value('type'))->toBe('note')
            ->and(DB::table('kami_entries')->where('type', 'other')->count())->toBe(1)
            ->and(Schema::hasTable('kami_type_memo'))->toBeFalse()
            ->and(column($entry, 'summary', 'kami_type_note'))->toBe(['fr' => 'Bonjour']);

        // What #194 is about: MySQL and MariaDB refuse a duplicate foreign key name, PostgreSQL a duplicate index name.
        ($this->declare)(Text::make('summary')->filterable());

        expect(array_map(fn (array $index): string => $index['name'], Schema::getIndexes('kami_type_memo')))->toHaveCount(2);

        // A foreign key still cascades after it has been written again.
        DB::table('kami_entries')->where('id', $entry)->delete();

        expect(DB::table('kami_type_note')->count())->toBe(0);
    });

    it('moves a type with no table', function () {
        $entry = memo();

        ($this->inMigration)("ContentMigrator::renameType('memo', 'note');");

        expect(DB::table('kami_entries')->where('id', $entry)->value('type'))->toBe('note');
    });

    it('renames the names Laravel gave an older table, and leaves an index written by hand', function () {
        Schema::create('kami_type_memo', function ($table) {
            $table->foreignUlid('entry_id')->constrained('kami_entries')->cascadeOnDelete();
            $table->string('locale', 20);
            $table->string('summary')->nullable();
            $table->unique(['entry_id', 'locale']);
            $table->index('summary', 'by_hand');
        });

        ($this->inMigration)("ContentMigrator::renameType('memo', 'note');");

        $names = array_column(Schema::getIndexes('kami_type_note'), 'name');

        expect($names)->toContain('by_hand')
            ->and(array_filter($names, fn (string $name): bool => str_contains($name, 'memo')))->toBe([]);
    });

    it('refuses a target that is taken, or that is no handle', function (string $to, string $message) {
        Schema::create('kami_type_taken', fn ($table) => $table->string('locale'));
        memo('held');
        memo();

        expect(fn () => ($this->inMigration)("ContentMigrator::renameType('memo', '{$to}');"))->toThrow(Exception::class, $message)
            ->and(DB::table('kami_entries')->where('type', 'memo')->count())->toBe(1);
    })->with([
        ['taken', 'kami_type_taken'],
        ['held', 'held'],
        ['Not-A-Handle', 'snake_case'],
    ]);

    it('refuses a type that does not exist', function () {
        expect(fn () => ($this->inMigration)("ContentMigrator::renameType('ghost', 'note');"))->toThrow(Exception::class, 'ghost');
    });
});

describe('renameField', function () {
    it('renames a column with its values, and frees its index name for a new Field', function () {
        ($this->declare)(Text::make('summary')->filterable());
        memoRow($entry = memo(), 'fr', ['summary' => 'Bonjour']);

        ($this->inMigration)("ContentMigrator::renameField('memo', 'summary', 'intro');");

        expect(column($entry, 'intro'))->toBe(['fr' => 'Bonjour'])
            ->and(Schema::hasColumn('kami_type_memo', 'summary'))->toBeFalse();

        ($this->declare)(Text::make('intro')->filterable(), Text::make('summary')->filterable());

        expect(Schema::hasColumn('kami_type_memo', 'summary'))->toBeTrue()
            ->and(Schema::getIndexes('kami_type_memo'))->toHaveCount(3);
    });

    it('rewrites a relation Field\'s References, for this type alone', function () {
        ($first = memo()) && ($other = memo('other')) && ($target = memo('target'));
        linked($first, 'related', null, $target);
        linked($other, 'related', null, $target);
        linked($first, 'kept', null, $target);

        ($this->inMigration)("ContentMigrator::renameField('memo', 'related', 'see_also');");

        expect(targets($first, 'see_also'))->toBe(['*' => [$target]])
            ->and(targets($first, 'related'))->toBe([])
            ->and(targets($first, 'kept'))->toBe(['*' => [$target]])
            ->and(targets($other, 'related'))->toBe(['*' => [$target]]);
    });

    it('refuses a column of the table itself, and a target already there', function (string $from, string $to) {
        ($this->declare)(Text::make('summary'), Text::make('intro'));

        expect(fn () => ($this->inMigration)("ContentMigrator::renameField('memo', '{$from}', '{$to}');"))->toThrow(InvalidArgumentException::class)
            ->and(Schema::hasColumn('kami_type_memo', 'summary'))->toBeTrue()
            ->and(Schema::hasColumn('kami_type_memo', 'locale'))->toBeTrue();
    })->with([
        ['locale', 'language'],
        ['summary', 'entry_id'],
        ['summary', 'intro'],
        ['summary', 'Not-A-Handle'],
    ]);
});

describe('dropType', function () {
    it('removes the Entries, all that hangs off them and the table, whatever holds them', function () {
        ($this->declare)(Text::make('summary'));
        ($parent = memo()) && translated($parent, 'fr');
        $child = memo();
        DB::table('kami_entries')->where('id', $child)->update(['parent_id' => $parent]);
        DB::table('kami_paths')->insert(['id' => (string) Str::ulid(), 'site' => 'default', 'locale' => 'fr', 'path' => 'gone', 'entry_id' => $parent, 'status' => 200]);
        $source = memo('other');
        linked($source, 'related', null, $parent);
        linked($parent, 'related', null, $source);

        Memo::$name = 'other_name';
        ($this->inMigration)("ContentMigrator::dropType('memo');");

        expect(Schema::hasTable('kami_type_memo'))->toBeFalse()
            ->and(DB::table('kami_entries')->pluck('id')->all())->toBe([$source])
            ->and(DB::table('kami_translations')->count())->toBe(0)
            ->and(DB::table('kami_paths')->count())->toBe(0)
            ->and(DB::table('kami_references')->count())->toBe(0);
    });

    it('refuses a type still declared', function () {
        $entry = memo();

        expect(fn () => ($this->inMigration)("ContentMigrator::dropType('memo');"))->toThrow(InvalidArgumentException::class, 'memo')
            ->and(DB::table('kami_entries')->where('id', $entry)->exists())->toBeTrue();
    });
});

describe('dropLocale', function () {
    it('removes the Locale everywhere, undeclared types included, and leaves the Entry and other Locales', function () {
        ($this->declare)(Text::make('summary'));
        Schema::create('kami_type_former', function ($table) {
            $table->foreignUlid('entry_id')->constrained('kami_entries')->cascadeOnDelete();
            $table->string('locale', 20);
            $table->string('summary')->nullable();
        });
        $entry = memo();
        $former = memo('former');
        $target = memo('target');

        foreach (['de', 'fr', '*'] as $locale) {
            translated($entry, $locale);
            memoRow($entry, $locale, ['summary' => $locale]);
            memoRow($former, $locale, ['summary' => $locale], 'kami_type_former');
            linked($entry, 'related', $locale === '*' ? null : $locale, $target);
            DB::table('kami_paths')->insert(['id' => (string) Str::ulid(), 'site' => 'default', 'locale' => $locale, 'path' => 'here', 'entry_id' => $entry, 'status' => 200]);
        }

        ($this->inMigration)("ContentMigrator::dropLocale('de');");

        expect(DB::table('kami_translations')->pluck('locale')->sort()->values()->all())->toBe(['*', 'fr'])
            ->and(column($entry, 'summary'))->toBe(['*' => '*', 'fr' => 'fr'])
            ->and(column($former, 'summary', 'kami_type_former'))->toBe(['*' => '*', 'fr' => 'fr'])
            ->and(array_keys(targets($entry, 'related')))->toBe(['*', 'fr'])
            ->and(DB::table('kami_paths')->pluck('locale')->sort()->values()->all())->toBe(['*', 'fr'])
            ->and(DB::table('kami_entries')->where('id', $entry)->exists())->toBeTrue();
    });

    it('refuses the Shared sentinel and a declared Locale', function (string $locale) {
        translated($entry = memo(), $locale);

        expect(fn () => ($this->inMigration)("ContentMigrator::dropLocale('{$locale}');"))->toThrow(InvalidArgumentException::class, $locale)
            ->and(DB::table('kami_translations')->where('entry_id', $entry)->count())->toBe(1);
    })->with(['*', 'en']);
});

describe('makeTranslatable', function () {
    it('moves a Shared value to the Locales holding a Translation, the default Locale when none does', function () {
        ($this->declare)(Text::make('summary'), Text::make('other'));
        $both = memo();
        translated($both, 'fr');
        translated($both, 'en');
        memoRow($both, '*', ['summary' => 'Partout', 'other' => 'Stays']);
        memoRow($both, 'en', ['other' => 'Kept']);
        $english = memo();
        translated($english, 'en');
        memoRow($english, '*', ['summary' => 'English']);
        memoRow($none = memo(), '*', ['summary' => 'Alone']);
        $blank = memo();
        translated($blank, 'fr');
        memoRow($blank, '*', ['other' => 'Only other']);

        ($this->inMigration)("ContentMigrator::makeTranslatable('memo', 'summary');");

        expect(column($both, 'summary'))->toBe(['en' => 'Partout', 'fr' => 'Partout'])
            ->and(column($both, 'other'))->toBe(['*' => 'Stays', 'en' => 'Kept'])
            ->and(column($english, 'summary'))->toBe(['en' => 'English'])
            ->and(column($none, 'summary'))->toBe(['fr' => 'Alone'])
            ->and(column($blank, 'summary'))->toBe([]);
    });

    it('copies a relation Field\'s shared links to the same Locales, in pick order', function () {
        ($one = memo('target')) && ($two = memo('target'));
        $entry = memo();
        translated($entry, 'fr');
        translated($entry, 'en');
        linked($entry, 'related', null, $two);
        linked($entry, 'related', null, $one, 1);
        linked($entry, 'kept', null, $one);
        linked($none = memo(), 'related', null, $one);

        ($this->inMigration)("ContentMigrator::makeTranslatable('memo', 'related');");

        expect(targets($entry, 'related'))->toBe(['en' => [$two, $one], 'fr' => [$two, $one]])
            ->and(targets($entry, 'kept'))->toBe(['*' => [$one]])
            ->and(targets($none, 'related'))->toBe(['fr' => [$one]]);
    });
});

describe('makeShared', function () {
    it('keeps one Locale\'s value and clears the others\' when they agree', function () {
        ($this->declare)(Text::make('summary'), Text::make('other'));
        memoRow($entry = memo(), 'fr', ['summary' => 'Pareil', 'other' => 'Stays']);
        memoRow($entry, 'en', ['summary' => 'Pareil']);
        memoRow($entry, 'de', ['summary' => null]);

        ($this->inMigration)("ContentMigrator::makeShared('memo', 'summary', keep: 'fr');");

        expect(column($entry, 'summary'))->toBe(['*' => 'Pareil'])
            ->and(column($entry, 'other'))->toBe(['fr' => 'Stays']);
    });

    it('undoes makeTranslatable', function () {
        ($this->declare)(Text::make('summary'));
        translated($entry = memo(), 'fr');
        translated($entry, 'en');
        memoRow($entry, '*', ['summary' => 'Partout']);

        ($this->inMigration)("ContentMigrator::makeTranslatable('memo', 'summary');");
        ($this->inMigration)("ContentMigrator::makeShared('memo', 'summary', keep: 'fr');");

        expect(column($entry, 'summary'))->toBe(['*' => 'Partout']);
    });

    it('refuses before writing anything while a differing value would be lost', function (?string $kept) {
        ($this->declare)(Text::make('summary'));
        memoRow($agrees = memo(), 'fr', ['summary' => 'Pareil']);
        memoRow($agrees, 'en', ['summary' => 'Pareil']);
        memoRow($differs = memo(), 'en', ['summary' => 'Different']);

        if ($kept !== null) {
            memoRow($differs, 'fr', ['summary' => $kept]);
        }

        expect(fn () => ($this->inMigration)("ContentMigrator::makeShared('memo', 'summary', keep: 'fr');"))->toThrow(RuntimeException::class, $differs)
            ->and(column($agrees, 'summary'))->toBe(['en' => 'Pareil', 'fr' => 'Pareil']);
    })->with(['another value' => 'Autre', 'no value in the kept Locale' => null]);

    it('discards the others when told to', function () {
        ($this->declare)(Text::make('summary'));
        memoRow($entry = memo(), 'fr', ['summary' => 'Garde']);
        memoRow($entry, 'en', ['summary' => 'Perdu']);
        memoRow($blank = memo(), 'en', ['summary' => 'Perdu']);

        ($this->inMigration)("ContentMigrator::makeShared('memo', 'summary', keep: 'fr', discardOthers: true);");

        expect(column($entry, 'summary'))->toBe(['*' => 'Garde'])
            ->and(column($blank, 'summary'))->toBe([]);
    });

    it('compares json decoded, whatever the order of its keys', function () {
        ($this->declare)(Repeater::make('links', [Text::make('label'), Text::make('url')]));
        memoRow($entry = memo(), 'fr', ['links' => '[{"label":"a","url":"b"}]']);
        memoRow($entry, 'en', ['links' => '[{"url":"b","label":"a"}]']);

        ($this->inMigration)("ContentMigrator::makeShared('memo', 'links', keep: 'fr');");

        expect(json_decode((string) DB::table('kami_type_memo')->where(['entry_id' => $entry, 'locale' => '*'])->value('links'), true))->toEqual([['label' => 'a', 'url' => 'b']]);
    });

    it('moves a relation Field\'s links, and compares them in pick order', function () {
        ($one = memo('target')) && ($two = memo('target'));
        $entry = memo();
        linked($entry, 'related', 'fr', $one);
        linked($entry, 'related', 'fr', $two, 1);
        linked($entry, 'related', 'en', $one);
        linked($entry, 'related', 'en', $two, 1);

        ($this->inMigration)("ContentMigrator::makeShared('memo', 'related', keep: 'fr');");

        expect(targets($entry, 'related'))->toBe(['*' => [$one, $two]]);

        $differs = memo();
        linked($differs, 'related', 'fr', $one);
        linked($differs, 'related', 'fr', $two, 1);
        linked($differs, 'related', 'en', $two);
        linked($differs, 'related', 'en', $one, 1);

        expect(fn () => ($this->inMigration)("ContentMigrator::makeShared('memo', 'related', keep: 'fr');"))->toThrow(RuntimeException::class, $differs);

        File::delete(File::glob(database_path('migrations/2100_*')));
        ($this->inMigration)("ContentMigrator::makeShared('memo', 'related', keep: 'fr', discardOthers: true);");

        expect(targets($differs, 'related'))->toBe(['*' => [$one, $two]]);
    });
});

describe('makeTypeTranslatable', function () {
    it('moves each shared Translation to the Locale, keeping its publication and leaving Field rows', function () {
        ($this->declare)(Text::make('summary'));
        translated($entry = memo(), '*', ['title' => 'Titre', 'status' => 'draft']);
        memoRow($entry, '*', ['summary' => 'Partout']);
        translated($other = memo('other'), '*');

        ($this->inMigration)("ContentMigrator::makeTypeTranslatable('memo', locale: 'fr');");

        $translation = DB::table('kami_translations')->where('entry_id', $entry)->sole();

        expect($translation->locale)->toBe('fr')
            ->and($translation->title)->toBe('Titre')
            ->and($translation->status)->toBe('draft')
            ->and($translation->updated_at)->toBe('2020-01-01 00:00:00')
            ->and(column($entry, 'summary'))->toBe(['*' => 'Partout'])
            ->and(DB::table('kami_translations')->where('entry_id', $other)->value('locale'))->toBe('*');
    });

    it('refuses a Locale no Site declares', function (string $locale) {
        translated($entry = memo(), '*');

        expect(fn () => ($this->inMigration)("ContentMigrator::makeTypeTranslatable('memo', locale: '{$locale}');"))->toThrow(InvalidArgumentException::class, $locale)
            ->and(DB::table('kami_translations')->where('entry_id', $entry)->value('locale'))->toBe('*');
    })->with(['de', '*']);
});

describe('makeTypeShared', function () {
    it('keeps the Locale\'s Translation as the shared one when the others hold the same', function () {
        translated($entry = memo(), 'fr', ['title' => 'Pareil', 'slug' => 'pareil']);
        translated($entry, 'en', ['title' => 'Pareil', 'slug' => 'pareil']);

        ($this->inMigration)("ContentMigrator::makeTypeShared('memo', keep: 'fr');");

        $translation = DB::table('kami_translations')->where('entry_id', $entry)->sole();

        expect($translation->locale)->toBe('*')
            ->and($translation->title)->toBe('Pareil');
    });

    it('refuses before writing anything while another Locale\'s would be lost', function (array $kept, array $other) {
        translated($agrees = memo(), 'fr');
        $differs = memo();

        if ($kept !== []) {
            translated($differs, 'fr', $kept);
        }

        translated($differs, 'en', $other);

        expect(fn () => ($this->inMigration)("ContentMigrator::makeTypeShared('memo', keep: 'fr');"))->toThrow(RuntimeException::class, $differs)
            ->and(DB::table('kami_translations')->where('entry_id', $agrees)->value('locale'))->toBe('fr');
    })->with([
        'a title' => [['title' => 'Titre'], ['title' => 'Title']],
        'a status' => [['title' => 'Same', 'slug' => 's'], ['title' => 'Same', 'slug' => 's', 'status' => 'draft']],
        'none in the kept Locale' => [[], ['title' => 'Title']],
    ]);

    it('discards the others when told to, leaving an Entry with nothing kept without a Translation', function () {
        translated($entry = memo(), 'fr', ['title' => 'Garde']);
        translated($entry, 'en', ['title' => 'Perdu']);
        translated($alone = memo(), 'en');

        ($this->inMigration)("ContentMigrator::makeTypeShared('memo', keep: 'fr', discardOthers: true);");

        expect(DB::table('kami_translations')->where('entry_id', $entry)->sole()->title)->toBe('Garde')
            ->and(DB::table('kami_translations')->where('entry_id', $entry)->sole()->locale)->toBe('*')
            ->and(DB::table('kami_translations')->where('entry_id', $alone)->count())->toBe(0)
            ->and(DB::table('kami_entries')->where('id', $alone)->exists())->toBeTrue();
    });
});

it('goes through more Entries than one batch holds', function () {
    ($this->declare)(Text::make('summary'));
    $ids = [];

    foreach (range(1, 501) as $_) {
        $ids[] = ['id' => (string) Str::ulid(), 'site' => 'default', 'type' => 'memo'];
    }

    foreach (array_chunk($ids, 100) as $chunk) {
        DB::table('kami_entries')->insert($chunk);
    }

    foreach (array_chunk($ids, 100) as $chunk) {
        DB::table('kami_type_memo')->insert(array_map(fn (array $entry): array => ['entry_id' => $entry['id'], 'locale' => '*', 'summary' => 'Partout'], $chunk));
    }

    ($this->inMigration)("ContentMigrator::makeTranslatable('memo', 'summary');");

    expect(DB::table('kami_type_memo')->where(['locale' => 'fr', 'summary' => 'Partout'])->count())->toBe(501)
        ->and(DB::table('kami_type_memo')->where('locale', '*')->whereNotNull('summary')->count())->toBe(0);
});

describe('what must not be lost', function () {
    it('tells json values apart strictly', function (string $kept, string $other) {
        ($this->declare)(Text::make('summary'));
        memoRow($entry = memo(), 'fr', ['summary' => $kept]);
        memoRow($entry, 'en', ['summary' => $other]);

        expect(fn () => ($this->inMigration)("ContentMigrator::makeShared('memo', 'summary', keep: 'fr');"))->toThrow(RuntimeException::class, $entry);
    })->with([
        'an empty list and text' => ['[]', 'Plain text'],
        'an empty list and null' => ['[]', 'null'],
        'a string and a number' => ['{"a":"1"}', '{"a":1}'],
        'null and false' => ['{"a":null}', '{"a":false}'],
    ]);

    it('refuses to keep the shared sentinel, or a Locale nothing holds, discarding or not', function (string $operation) {
        ($this->declare)(Text::make('summary'));
        translated($entry = memo(), 'fr');
        memoRow($entry, 'fr', ['summary' => 'Bonjour']);

        foreach (['*', 'xx'] as $keep) {
            expect(fn () => ($this->inMigration)(sprintf($operation, $keep)))->toThrow(InvalidArgumentException::class, $keep);
            File::delete(File::glob(database_path('migrations/2100_*')));
        }

        expect(column($entry, 'summary'))->toBe(['fr' => 'Bonjour'])
            ->and(DB::table('kami_translations')->where('entry_id', $entry)->value('locale'))->toBe('fr');
    })->with([
        "ContentMigrator::makeShared('memo', 'summary', keep: '%s', discardOthers: true);",
        "ContentMigrator::makeTypeShared('memo', keep: '%s', discardOthers: true);",
    ]);

    it('keeps a Locale no longer declared, when Entries hold it', function () {
        ($this->declare)(Text::make('summary'));
        memoRow($entry = memo(), 'de', ['summary' => 'Hallo']);

        ($this->inMigration)("ContentMigrator::makeShared('memo', 'summary', keep: 'de');");

        expect(column($entry, 'summary'))->toBe(['*' => 'Hallo']);
    });

    it('counts a shared value makeShared would overwrite, and runs again over what is already shared', function () {
        ($this->declare)(Text::make('summary'));
        memoRow($shared = memo(), '*', ['summary' => 'Partout']);
        memoRow($differs = memo(), '*', ['summary' => 'Partout']);
        memoRow($differs, 'fr', ['summary' => 'Autre']);

        expect(fn () => ($this->inMigration)("ContentMigrator::makeShared('memo', 'summary', keep: 'fr');"))->toThrow(RuntimeException::class, $differs)
            ->and(column($differs, 'summary'))->toBe(['*' => 'Partout', 'fr' => 'Autre']);

        DB::table('kami_type_memo')->where('entry_id', $differs)->delete();
        memoRow($other = memo(), 'fr', ['summary' => 'Bonjour']);
        File::delete(File::glob(database_path('migrations/2100_*')));
        ($this->inMigration)("ContentMigrator::makeShared('memo', 'summary', keep: 'fr');");

        expect(column($shared, 'summary'))->toBe(['*' => 'Partout'])
            ->and(column($other, 'summary'))->toBe(['*' => 'Bonjour']);
    });

    it('counts shared links makeShared would delete', function () {
        ($one = memo('target')) && ($two = memo('target'));
        linked($entry = memo(), 'related', null, $one);
        linked($entry, 'related', 'fr', $two);

        expect(fn () => ($this->inMigration)("ContentMigrator::makeShared('memo', 'related', keep: 'fr');"))->toThrow(RuntimeException::class, $entry)
            ->and(targets($entry, 'related'))->toBe(['*' => [$one], 'fr' => [$two]]);
    });

    it('replaces a shared Translation holding the same as the kept one', function () {
        translated($entry = memo(), 'fr');
        translated($entry, '*', ['title' => 'Title fr', 'slug' => 'slug-fr']);

        ($this->inMigration)("ContentMigrator::makeTypeShared('memo', keep: 'fr');");

        expect(DB::table('kami_translations')->where('entry_id', $entry)->sole()->locale)->toBe('*');
    });

    it('refuses to overwrite a Locale value with makeTranslatable, before writing anything', function () {
        ($this->declare)(Text::make('summary'));
        translated($first = memo(), 'fr');
        memoRow($first, '*', ['summary' => 'Partout']);
        translated($second = memo(), 'fr');
        memoRow($second, '*', ['summary' => 'Partout']);
        memoRow($second, 'fr', ['summary' => 'Autre']);

        expect(fn () => ($this->inMigration)("ContentMigrator::makeTranslatable('memo', 'summary');"))->toThrow(RuntimeException::class, $second)
            ->and(column($first, 'summary'))->toBe(['*' => 'Partout']);
    });

    it('does not double links makeTranslatable finds already there', function () {
        $target = memo('target');
        translated($entry = memo(), 'fr');
        linked($entry, 'related', null, $target);
        linked($entry, 'related', 'fr', $target);

        ($this->inMigration)("ContentMigrator::makeTranslatable('memo', 'related');");

        expect(targets($entry, 'related'))->toBe(['fr' => [$target]]);
    });

    it('sends a value to the default Locale when the Entry\'s only Translation is in an undeclared one', function () {
        ($this->declare)(Text::make('summary'));
        translated($entry = memo(), 'de');
        memoRow($entry, '*', ['summary' => 'Partout']);

        ($this->inMigration)("ContentMigrator::makeTranslatable('memo', 'summary');");

        expect(column($entry, 'summary'))->toBe(['fr' => 'Partout']);
    });

    it('refuses renaming a relation Field onto links or a column already there', function (string $to) {
        ($this->declare)(Text::make('summary'));
        $target = memo('target');
        linked($entry = memo(), 'related', null, $target);
        linked($entry, 'taken', null, $target);

        expect(fn () => ($this->inMigration)("ContentMigrator::renameField('memo', 'related', '{$to}');"))->toThrow(InvalidArgumentException::class, $to)
            ->and(targets($entry, 'related'))->toBe(['*' => [$target]]);
    })->with(['taken', 'summary']);
});
