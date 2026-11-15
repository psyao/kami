<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Events\EntrySaved;
use Psyao\Kami\Redirects\Actions\SaveRedirect;
use Psyao\Kami\Redirects\Events\RedirectSaved;
use Psyao\Kami\Redirects\Redirect;
use Workbench\App\Content\Page;

// Not RefreshDatabase: its transaction would make the action's a nested one, which Laravel never retries.
uses(DatabaseMigrations::class);

it('saves over a concurrent save that deadlocks it at a new source', function (): void {
    Event::fake([RedirectSaved::class]);
    $config = config('database.connections.' . config('database.default'));
    $other = new mysqli($config['host'], $config['username'], $config['password'], $config['database'], (int) $config['port']);
    $lookups = 0;

    DB::beforeExecuting(function (string $query) use ($other, &$lookups): void {
        if (str_contains($query, 'for update') && ++$lookups === 2) {
            // The retry's lookup: the concurrent save, its insert granted once the deadlock rolled this one back, commits.
            $other->reap_async_query();
            $other->commit();
        }

        if (! str_starts_with($query, 'insert into `kami_paths`') || $lookups !== 1) {
            return;
        }

        // The concurrent save, between this one's lookup and its insert: its own lookup takes the same gap lock, so its insert waits on this
        // one's, and this one's then closes the cycle. Its rows elsewhere make it the heavier transaction, so InnoDB rolls this one back.
        $other->begin_transaction();

        $other->query(sprintf("insert into kami_task_runs (id, task, description, last_run_at) values ('%s', 'a', '', now()), ('%s', 'b', '', now()), ('%s', 'c', '', now())", Str::ulid(), Str::ulid(), Str::ulid()));

        $other->query("select * from kami_paths where site = 'default' and locale = 'fr' and path = 'blog' for update");
        $other->query(sprintf("insert into kami_paths (id, site, locale, path, status, target) values ('%s', 'default', 'fr', 'blog', 302, '/winner')", Str::ulid()), MYSQLI_ASYNC);

        foreach (range(1, 100) as $_) {
            if (DB::selectOne("select count(*) as waiting from information_schema.innodb_trx where trx_state = 'LOCK WAIT' and trx_mysql_thread_id = ?", [$other->thread_id])->waiting > 0) {
                return;
            }

            Sleep::usleep(20_000);
        }
    });

    try {
        resolve(SaveRedirect::class)->handle('/blog', 301, '/elsewhere');
    } finally {
        // A failure leaves the concurrent save open, holding locks the closing rollback of migrations would wait on.
        DB::statement("kill {$other->thread_id}");
    }

    expect(Redirect::query()->sole()->only(['status', 'target']))->toBe(['status' => 301, 'target' => '/elsewhere'])
        ->and(array_column(auditEvents(), 'event'))->toBe(['kami.redirects.updated'])
        ->and($lookups)->toBe(2);
    Event::assertDispatched(RedirectSaved::class, fn (RedirectSaved $event): bool => ! $event->created);
})->skip(fn (): bool => ! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) || ! extension_loaded('mysqli'), 'A locking read of a missing row takes a gap lock on InnoDB only; the concurrent save needs mysqli.');

it('refuses an Entry\'s new Path a concurrent Redirect save takes, rather than deadlocking', function (): void {
    Event::fake([EntrySaved::class]);
    $config = config('database.connections.' . config('database.default'));
    $other = new mysqli($config['host'], $config['username'], $config['password'], $config['database'], (int) $config['port']);
    $inserts = 0;

    DB::beforeExecuting(function (string $query) use ($other, &$inserts): void {
        if (! str_starts_with($query, 'insert into `kami_paths`') || ++$inserts !== 1) {
            return;
        }

        // A concurrent SaveRedirect at the Entry's new Path, between this save's reads and its insert. Its rows elsewhere make it the
        // heavier transaction, so InnoDB rolls this one back should the two deadlock.
        $other->begin_transaction();
        $other->query(sprintf("insert into kami_task_runs (id, task, description, last_run_at) values ('%s', 'a', '', now()), ('%s', 'b', '', now()), ('%s', 'c', '', now())", Str::ulid(), Str::ulid(), Str::ulid()));
        $other->query("select * from kami_paths where site = 'default' and locale = 'fr' and path = 'blog' for update");
        $other->query(sprintf("insert into kami_paths (id, site, locale, path, status, target) values ('%s', 'default', 'fr', 'blog', 302, '/winner')", Str::ulid()), MYSQLI_ASYNC);

        foreach (range(1, 25) as $_) {
            if (DB::selectOne("select count(*) as waiting from information_schema.innodb_trx where trx_state = 'LOCK WAIT' and trx_mysql_thread_id = ?", [$other->thread_id])->waiting > 0) {
                // Its insert waits on a lock this save holds: this save's insert closes the cycle.
                return;
            }

            Sleep::usleep(20_000);
        }

        // Nothing of this save's holds it back: it commits first, and this save's insert meets its row.
        $other->reap_async_query();
        $other->commit();
    });

    try {
        expect(fn () => resolve(SaveEntry::class)->handle(Page::class, ['title' => 'Blog', 'status' => 'published'], locale: 'fr'))
            ->toThrow(function (ValidationException $e): void {
                expect($e->errors())->toBe(['slug' => [__('validation.unique', ['attribute' => 'slug'])]]);
            });
    } finally {
        DB::statement("kill {$other->thread_id}");
    }

    expect(Entry::query()->withUnpublished()->count())->toBe(0);
    expect(Redirect::query()->sole()->only(['status', 'target']))->toBe(['status' => 302, 'target' => '/winner']);
    expect(auditEvents())->toBe([]);
    Event::assertNotDispatched(EntrySaved::class);
})->skip(fn (): bool => ! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) || ! extension_loaded('mysqli'), 'A locking read or delete of a missing row takes a gap lock on InnoDB only; the concurrent save needs mysqli.');
