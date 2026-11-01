<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Internal\ContentModelDrift;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Content\Internal\ReorderEntries;
use Psyao\Kami\Facades\Kami;
use Psyao\Kami\Routing\Internal\Path;
use Psyao\Kami\Routing\Internal\SyncPaths;
use Psyao\Kami\Tests\Volume\Dataset;
use Workbench\App\Content\Project;
use Workbench\App\Models\User;

/** The query count of the page at the small size, measured before the dataset grows. */
function smallDatasetPageQueries(?int $set = null): ?int
{
    static $count = null;

    return $count = $set ?? $count;
}

/** Runs and reports its duration and peak memory: the suite's numbers are read from the job's log. */
function measure(string $what, Closure $run): mixed
{
    memory_reset_peak_usage();
    $start = microtime(true);
    $result = $run();
    fwrite(STDERR, sprintf("\n[volume] %-44s %8.3f s %6d MB", $what, microtime(true) - $start, memory_get_peak_usage(true) / 1048576));

    return $result;
}

function pageQueries(): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    test()->get('/project/project-30')->assertOk()->assertSee('Tag 1');
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

/** The engine's plan of a query, on one line. */
function plan(Builder $query): string
{
    $base = $query->toBase();
    $explain = DB::getDriverName() === 'sqlite' ? 'explain query plan ' : 'explain ';

    return collect(DB::select($explain . $base->toSql(), $base->getBindings()))->map(fn (object $row): string => (string) json_encode($row))->implode(' | ');
}

beforeEach(function () {
    Kami::routes();

    if (smallDatasetPageQueries() === null) {
        Artisan::call('migrate:fresh');
        $dataset = new Dataset;
        $dataset->fill(120);
        smallDatasetPageQueries(pageQueries());
        measure('Generate 30,000 Entries', fn () => $dataset->fill(Dataset::ENTRIES));

        // MySQL and MariaDB keep their statistics up to date; PostgreSQL's arrive with autovacuum, some time later.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('analyze');
        }
    }
});

it('holds Entries in two Locales, with relations', function () {
    expect(DB::table('kami_entries')->count())->toBeGreaterThanOrEqual(Dataset::ENTRIES)
        ->and(DB::table('kami_translations')->where('locale', 'en')->count())->toBeGreaterThan(29_000)
        ->and(DB::table('kami_references')->count())->toBeGreaterThan(100_000);
});

it('answers a page in the same number of queries as the small dataset', function () {
    expect(measure('Page', fn () => pageQueries()))->toBe(smallDatasetPageQueries());
});

it('walks a listing in its index, sorting nothing', function () {
    $query = Entry::query()->ofType(Project::class)->ordered()->limit(50);

    expect(plan($query))->toContain('kami_entries_type_position_id_index')->not->toContain('filesort')->not->toContain('Sort')->not->toContain('TEMP B-TREE')
        ->and(measure('Listing, 50 rows', fn () => (clone $query)->get()))->toHaveCount(50);

    measure('Listing in a Site', fn () => Entry::query()->where('site', 'default')->ofType(Project::class)->ordered()->limit(50)->get());
    measure('Listing with Drafts', fn () => Entry::query()->withUnpublished()->ofType(Project::class)->ordered()->limit(50)->get());
});

it('checks drift and reports stranded values', function () {
    $drift = resolve(ContentModelDrift::class);

    expect(measure('ContentModelDrift::problems()', fn () => $drift->problems()))->toBe([])
        ->and(measure('ContentModelDrift::strandedValues()', fn () => $drift->strandedValues()))->toBe([]);

    expect(measure('kami:doctor', fn () => Artisan::call('kami:doctor')))->toBe(0);
});

it('completes SyncPaths, with nothing to do and with every row to write', function () {
    $nothing = ['created' => 0, 'rewritten' => 0, 'deleted' => 0, 'clashes' => []];
    $sync = resolve(SyncPaths::class);

    expect(measure('SyncPaths, nothing to do', fn () => $sync()))->toBe($nothing);

    $paths = Path::query()->count();
    Path::query()->delete();

    expect(measure('SyncPaths, every row to write', fn () => $sync())['created'])->toBe($paths)
        ->and($sync())->toBe($nothing);
});

it('reads Fields at a cost a page does not see', function () {
    $entries = measure('Hydrate 2,000 Entries', fn () => Entry::query()->ofType(Project::class)->limit(2000)->get());

    measure('Read 3 Fields of 2,000 Entries', fn () => $entries->each(fn (Entry $entry) => [$entry->client, $entry->story, $entry->kind]));
    expect($entries)->toHaveCount(2000);
});

it('reorders a type whose Positions tie within a shared host\'s limits', function () {
    $type = resolve(ContentTypes::class)->find(Project::class);
    $ids = Entry::query()->withUnpublished()->ofType(Project::class)->ordered()->limit(50)->pluck('id')->all();
    $start = microtime(true);

    DB::beginTransaction();

    try {
        measure('First reorder of tied Positions', fn () => resolve(ReorderEntries::class)->handle($type, array_reverse($ids)));

        // Half of Infomaniak's web `max_execution_time` and `memory_limit`: a runner is no shared host.
        expect(microtime(true) - $start)->toBeLessThan(30)
            ->and(memory_get_peak_usage(true))->toBeLessThan(320 * 1024 * 1024)
            ->and(Entry::query()->withUnpublished()->ofType(Project::class)->ordered()->limit(50)->pluck('id')->all())->toBe(array_reverse($ids));

        measure('Next reorder', fn () => resolve(ReorderEntries::class)->handle($type, $ids));
    } finally {
        DB::rollBack();
    }
});

it('opens the admin table', function () {
    // Filament opens its panel to a User that is not a `FilamentUser` in the local environment only.
    config(['app.env' => 'local']);
    $this->actingAs(User::forceCreate(['name' => 'Ada', 'email' => uniqid() . '@example.test', 'password' => 'secret']));
    DB::table('kami_role_user')->insert(['id' => (string) Str::ulid(), 'user_id' => auth()->id(), 'role' => 'administrator']);

    measure('Admin table', fn () => $this->get('/admin/projects')->assertOk());
});
