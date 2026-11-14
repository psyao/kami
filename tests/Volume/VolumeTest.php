<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Psyao\Kami\Audit\Internal\AuditEvent;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Internal\ContentModelDrift;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Content\Internal\ReorderEntries;
use Psyao\Kami\Facades\Kami;
use Psyao\Kami\Routing\Internal\Path;
use Psyao\Kami\Routing\Internal\SyncPaths;
use Psyao\Kami\Seo\Internal\Sitemap;
use Psyao\Kami\Tests\Volume\Dataset;
use Workbench\App\Content\Project;
use Workbench\App\Content\Tag;
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

/** The query counts of an Archive's page and a Term Archive's at the small size, measured before the dataset grows. */
function smallDatasetArchiveQueries(?array $set = null): ?array
{
    static $counts = null;

    return $counts = $set ?? $counts;
}

/**
 * Workbench's Article Archive, and Tag 1's Term Archive, its Tag given an address for the test: a Term Archive needs a routable Taxonomy.
 *
 * @return list<int>
 */
function archiveQueries(): array
{
    (fn () => $this->routable = true)->call(resolve(ContentTypes::class)->find(Tag::class));
    View::addLocation(dirname(__DIR__) . '/Fixtures/archive');
    // Tag 1's address in both Locales, as `SaveEntry` would store it for a routable Tag, gone afterwards: `SyncPaths` would delete it.
    $tag = DB::table('kami_translations')->where('title', 'Tag 1')->value('entry_id');
    DB::table('kami_translations')->where('entry_id', $tag)->update(['slug' => 'tag-1']);
    DB::table('kami_paths')->insert(array_map(fn (string $locale): array => ['id' => (string) Str::ulid(), 'site' => 'default', 'locale' => $locale, 'path' => 'tag/tag-1', 'entry_id' => $tag, 'status' => 200], ['fr', 'en']));
    $counts = [];

    try {
        foreach (['/articles' => 'Article', '/tag/tag-1' => 'Project'] as $uri => $listed) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            measure("GET {$uri}", fn () => test()->get($uri)->assertOk()->assertSee($listed));
            $counts[] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }
    } finally {
        DB::table('kami_paths')->where('entry_id', $tag)->delete();
        DB::table('kami_translations')->where('entry_id', $tag)->update(['slug' => null]);
    }

    return $counts;
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
        smallDatasetArchiveQueries(archiveQueries());
        measure('Generate 30,000 Entries', fn () => $dataset->fill(Dataset::ENTRIES));

        // Statistics as a site that has been running has them. PostgreSQL's arrive with autovacuum, some time later. MySQL and
        // MariaDB recalculate theirs in the background at a moment the suite cannot predict: one taken halfway through the
        // inserts made MySQL read every Translation for each Entry's visibility check, 24 s for 2,000 Entries (#222).
        match (DB::getDriverName()) {
            'pgsql' => DB::statement('analyze'),
            'mysql', 'mariadb' => DB::statement('analyze table kami_entries, kami_translations, kami_paths, kami_references, kami_type_project, kami_type_article'),
            default => null,
        };
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

// 1.8 s for the Term Archive on SQLite while its links were read through a correlated `exists`, 47 ms through an `in`: the times are reported only.
it('answers an Archive and a Term Archive in the same number of queries as the small dataset', function () {
    expect(archiveQueries())->toBe(smallDatasetArchiveQueries());
});

it('walks a listing in its index, sorting nothing, the Trash left out', function () {
    $query = Entry::query()->ofType(Project::class)->ordered()->limit(50);

    expect($query->toBase()->toSql())->toContain('deleted_at')
        ->and(plan($query))->toContain('kami_entries_type_position_id_index')->not->toContain('filesort')->not->toContain('Sort')->not->toContain('TEMP B-TREE')
        ->and(measure('Listing, 50 rows', fn () => (clone $query)->get()))->toHaveCount(50);

    // #222: an `exists` made MariaDB materialise every published Translation, and MySQL sort the type through the Site index.
    $inSite = Entry::query()->where('site', 'default')->ofType(Project::class)->ordered()->limit(50);
    expect(plan($inSite))->toContain('kami_entries_type_position_id_index')->not->toContain('MATERIALIZED')->not->toContain('filesort');
    // 138 ms before #222's fix on MariaDB, 5 to 10 ms after: the plan above holds the fix, the time is reported only, as a
    // shared runner took 0.62 s (#454).
    expect(measure('Listing in a Site', fn () => (clone $inSite)->get()))->toHaveCount(50);
    measure('Listing with Drafts', fn () => Entry::query()->withUnpublished()->ofType(Project::class)->ordered()->limit(50)->get());
});

it('checks drift and reports stranded values', function () {
    $drift = resolve(ContentModelDrift::class);

    expect(measure('ContentModelDrift::problems()', fn () => $drift->problems()))->toBe([])
        ->and(measure('ContentModelDrift::strandedValues()', fn () => $drift->strandedValues()))->toBe([]);

    // The doctor expects the shared cache store and the `public/media` link `kami:install` leaves.
    withConversionsServed();
    withRunningSite();
    expect(measure('kami:doctor', fn () => Artisan::call('kami:doctor')))->toBe(0);
});

it('completes SyncPaths, with nothing to do and with every row to write', function () {
    $nothing = ['created' => 0, 'rewritten' => 0, 'deleted' => 0, 'clashes' => []];
    $sync = resolve(SyncPaths::class);

    expect(measure('SyncPaths, nothing to do', fn () => $sync()))->toBe($nothing);

    // SyncPaths owns the 200 rows: a deleted Entry's 301 or 410 has no Translation left to rebuild it from.
    $paths = Path::query()->where('status', 200)->count();
    Path::query()->where('status', 200)->delete();

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

it('builds the sitemap in one run within a shared host\'s memory', function () {
    app()->usePublicPath($public = temporaryPath());
    app()->useStoragePath($storage = temporaryPath());
    File::ensureDirectoryExists($public);

    try {
        // Two Locales of every Project and Article, Tags having no address, beside the Workbench seeder's pages.
        expect(measure('Sitemap of 30,000 Entries', fn () => Sitemap::build()))->toBeGreaterThanOrEqual(59_000)
            // Half of Infomaniak's web `memory_limit`: a missing sitemap is built in a request.
            ->and(memory_get_peak_usage(true))->toBeLessThan(320 * 1024 * 1024)
            ->and(array_map(fn (string $url) => basename($url), Kami::sitemapUrls()))->toBe(['fr-1.xml', 'fr-2.xml', 'fr-3.xml', 'en-1.xml', 'en-2.xml', 'en-3.xml'])
            ->and(substr_count(File::get("{$public}/sitemaps/fr-1.xml"), '<url>'))->toBe(Sitemap::URLS_PER_FILE);
    } finally {
        File::deleteDirectory($public);
        File::deleteDirectory($storage);
    }
});

function signInAsAdministrator(): void
{
    // Filament opens its panel to a User that is not a `FilamentUser` in the local environment only.
    config(['app.env' => 'local']);
    // A manager without MFA is sent to set it up (#362).
    test()->actingAs(withMfa(User::forceCreate(['name' => 'Ada', 'email' => uniqid() . '@example.test', 'password' => 'secret'])));
    DB::table('kami_role_user')->insert(['id' => (string) Str::ulid(), 'user_id' => auth()->id(), 'role' => 'administrator']);
}

it('opens the admin table', function () {
    signInAsAdministrator();

    measure('Admin table', fn () => $this->get('/admin/projects')->assertOk());
});

it('opens the Audit log over 12 months of events, its User filter reading nothing from the log', function () {
    signInAsAdministrator();
    $events = ['kami.auth.signed-in', 'kami.auth.sign-in-failed', 'kami.entries.saved', 'kami.media.saved', 'acme.invoices.imported'];

    // One event every four minutes for a year, a sign-in site's retention: sign-ins and failures name a User as subject.
    foreach (array_chunk(range(1, 131_400), 500) as $numbers) {
        DB::table('kami_audit_events')->insert(array_map(fn (int $number): array => [
            'id' => (string) Str::ulid(), 'occurred_at' => now('UTC')->subMinutes($number * 4), 'user_id' => $number % 3 === 0 ? null : auth()->id(),
            'actor' => $number % 3 === 0 ? 'system' : 'user', 'event' => $events[$number % 5], 'subject_type' => 'user', 'subject_id' => (string) ($number % 200),
            'site' => 'default', 'ip' => '192.0.2.0', 'context' => '{}',
        ], $numbers));
    }

    if (DB::getDriverName() !== 'sqlite') {
        DB::statement(DB::getDriverName() === 'pgsql' ? 'analyze kami_audit_events' : 'analyze table kami_audit_events');
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $start = microtime(true);
    measure('Audit log, 131,400 events', fn () => $this->get('/admin/audit-events')->assertOk());
    // 0.2 to 0.4 s on every engine; loose, as a shared runner is slower (#454).
    expect(microtime(true) - $start)->toBeLessThan(5);
    $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_contains($sql, 'kami_audit_events'));
    DB::disableQueryLog();

    // #378's two scans for the User filter's options are gone; the Event filter's read its index.
    expect($queries->filter(fn (string $sql): bool => preg_match('/distinct.*(user_id|subject_id)/', $sql) === 1))->toBeEmpty()
        ->and(plan(AuditEvent::query()->latest('occurred_at')->orderByDesc('id')->limit(10)))->toContain('kami_audit_events_occurred_at_index');
});
