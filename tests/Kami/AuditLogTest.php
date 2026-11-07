<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Psyao\Kami\Admin\Internal\EntryResource\ListEntries;
use Psyao\Kami\Audit\Actions\RecordAuditEvent;
use Psyao\Kami\Audit\AuditContext;
use Psyao\Kami\Audit\Events\AuditEventRecorded;
use Psyao\Kami\Content\Actions\DeleteEntry;
use Psyao\Kami\Content\Actions\RestoreEntry;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Actions\TrashEntry;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Events\EntryPublished;
use Psyao\Kami\Facades\Kami;
use Psyao\Kami\Media\Actions\DeleteMedia;
use Psyao\Kami\Media\Actions\SaveMedia;
use Workbench\App\Content\Article;
use Workbench\App\Content\Page;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

/**
 * The Audit log's rows, oldest first, as a site's own query would read them. Contexts by key: MySQL's JSON keeps no order.
 *
 * @return list<array<string, mixed>>
 */
function auditEvents(): array
{
    return DB::table('kami_audit_events')->orderBy('occurred_at')->orderBy('id')->get()
        ->map(function (object $row): array {
            /** @var array<string, mixed> $context */
            $context = json_decode((string) $row->context, true);
            ksort($context);

            return [...(array) $row, 'context' => $context];
        })
        ->all();
}

function auditUser(): User
{
    return User::forceCreate(['name' => 'Ada Lovelace', 'email' => uniqid() . '@example.test', 'password' => 'secret']);
}

it('records a site’s own event with its User, subject and context', function (): void {
    $this->freezeSecond();
    $ada = auditUser();
    $grace = auditUser();

    resolve(RecordAuditEvent::class)->handle('acme.invoices.exported', $grace, ['format' => 'csv', 'rows' => 12], $ada);

    expect(auditEvents())->toHaveCount(1)
        ->and(auditEvents()[0])->toMatchArray([
            'occurred_at' => now()->format('Y-m-d H:i:s'),
            'user_id' => $ada->getKey(),
            'actor' => 'user',
            'event' => 'acme.invoices.exported',
            'subject_type' => 'user',
            'subject_id' => (string) $grace->getKey(),
            'site' => null,
            'ip' => null,
            'context' => ['format' => 'csv', 'rows' => 12],
        ]);
});

/** The rest of the test runs as a web request from the address: Pest itself runs in the console. */
function overHttpFrom(string $ip): void
{
    (fn () => $this->isRunningInConsole = false)->call(app());
    app()->instance('request', Request::create('/', server: ['REMOTE_ADDR' => $ip]));
}

it('records `cli` in the console with no actor, and no IP', function (): void {
    resolve(RecordAuditEvent::class)->handle('acme.cache.cleared');

    expect(auditEvents()[0])->toMatchArray(['user_id' => null, 'actor' => 'cli', 'ip' => null, 'subject_type' => null, 'subject_id' => null, 'context' => []]);
});

it('records `system` over HTTP with no actor, and `user` with one', function (): void {
    overHttpFrom('203.0.113.42');
    resolve(RecordAuditEvent::class)->handle('acme.webhook.received');
    resolve(RecordAuditEvent::class)->handle('acme.invoices.exported', actor: auditUser());

    expect(array_column(auditEvents(), 'actor'))->toBe(['system', 'user']);
});

it('keeps the network of the IP, not the connection: /24 for IPv4, /48 for IPv6', function (string $ip, string $stored): void {
    overHttpFrom($ip);

    resolve(RecordAuditEvent::class)->handle('acme.invoices.exported', actor: auditUser());

    expect(auditEvents()[0]['ip'])->toBe($stored);
})->with([
    'IPv4' => ['203.0.113.42', '203.0.113.0'],
    'IPv6' => ['2001:db8:85a3:8d3:1319:8a2e:370:7348', '2001:db8:85a3::'],
]);

it('stores a subject that is gone by its type and id, and Kami’s models under short type names with their Site', function (): void {
    $entry = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Kami'], locale: 'fr');

    resolve(RecordAuditEvent::class)->handle('acme.entries.archived', $entry);
    resolve(RecordAuditEvent::class)->handle('acme.invoices.deleted', ['invoice', 42]);

    expect(array_map(fn (array $event): array => [$event['subject_type'], $event['subject_id'], $event['site']], auditEvents()))
        ->toBe([['entry', $entry->id, 'default'], ['invoice', '42', null]]);
});

it('refuses a context that is not a short flat object, and records nothing', function (array $context, string $message): void {
    expect(fn () => resolve(RecordAuditEvent::class)->handle('acme.invoices.exported', context: $context))
        ->toThrow(InvalidArgumentException::class, $message);

    expect(auditEvents())->toBe([]);
})->with([
    'nested' => [['old' => ['title' => 'Kami']], 'a flat object of scalar values: [old] is not'],
    'a list' => [['csv'], 'a flat object of scalar values: [0] is not'],
    'too long a string' => [['title' => str_repeat('a', 256)], 'value [title] is over 255 characters'],
    'too many keys' => [array_fill_keys(range('a', 'u'), 1), 'at most 20 keys'],
]);

/**
 * The Audit events recorded, as event, subject id and context.
 *
 * @return list<array{string, string|null, array<string, mixed>}>
 */
function auditTrail(): array
{
    return array_map(fn (array $event): array => [$event['event'], $event['subject_id'], $event['context']], auditEvents());
}

describe('SaveEntry', function (): void {
    it('records a publication, a scheduled one too, with the User and Locale', function (): void {
        $ada = auditUser();
        $now = auditSave(['title' => 'Now', 'status' => 'published'], locale: 'fr', actor: $ada);
        $later = auditSave(['title' => 'Later', 'status' => 'published', 'published_at' => now()->addDay()], locale: 'fr', actor: $ada);

        expect(auditTrail())->toBe([
            ['kami.entries.published', $now->id, ['locale' => 'fr']],
            ['kami.entries.published', $later->id, ['locale' => 'fr']],
        ])->and(array_column(auditEvents(), 'user_id'))->toBe([$ada->getKey(), $ada->getKey()]);
    });

    it('records nothing for a Draft, nor for a published Entry saved again on the same date', function (): void {
        $draft = auditSave(['title' => 'Draft'], locale: 'fr');
        auditSave(['title' => 'Draft, again'], $draft, 'fr');
        $published = auditSave(['title' => 'Published', 'status' => 'published'], locale: 'fr');
        DB::table('kami_audit_events')->delete();

        auditSave(['title' => 'Published, retitled'], $published, 'fr');

        expect(auditEvents())->toBe([]);
    });

    it('records an unpublication, and a changed date on a visible or Scheduled Translation with the old and new date', function (): void {
        $entry = auditSave(['title' => 'Kami', 'status' => 'published', 'published_at' => '2026-11-01 08:00:00'], locale: 'fr');
        auditSave(['published_at' => '2026-12-01 08:00:00'], $entry, 'fr');
        auditSave(['status' => 'draft'], $entry, 'fr');
        // A Draft's date is not visible: changing it records nothing.
        auditSave(['published_at' => '2027-01-01 08:00:00'], $entry, 'fr');

        expect(array_slice(auditTrail(), 1))->toBe([
            ['kami.entries.publication-date-changed', $entry->id, ['from' => '2026-11-01T08:00:00+00:00', 'locale' => 'fr', 'to' => '2026-12-01T08:00:00+00:00']],
            ['kami.entries.unpublished', $entry->id, ['locale' => 'fr']],
        ]);
    });

    it('records nothing when the publication rolls back, in the caller’s transaction', function (): void {
        expect(fn () => DB::transaction(function (): void {
            auditSave(['title' => 'Kami', 'status' => 'published'], locale: 'fr');

            throw new RuntimeException('The import failed further on.');
        }))->toThrow(RuntimeException::class);

        expect(auditEvents())->toBe([]);
    });
});

/** @param  array<string, mixed>  $values */
function auditSave(array $values, ?Entry $entry = null, ?string $locale = null, ?User $actor = null): Entry
{
    return resolve(SaveEntry::class)->handle(Page::class, $values, $entry, $locale, $actor);
}

it('records the Trash, a restore and a deletion for good, with the User', function (): void {
    $ada = auditUser();
    $entry = auditSave(['title' => 'Kami'], locale: 'fr');

    resolve(TrashEntry::class)->handle($entry, $ada);
    resolve(RestoreEntry::class)->handle($entry, $ada);
    resolve(TrashEntry::class)->handle($entry, $ada);
    resolve(DeleteEntry::class)->handle($entry, actor: $ada);

    expect(auditTrail())->toBe([
        ['kami.entries.trashed', $entry->id, []],
        ['kami.entries.restored', $entry->id, []],
        ['kami.entries.trashed', $entry->id, []],
        ['kami.entries.force-deleted', $entry->id, []],
    ])->and(array_unique(array_column(auditEvents(), 'user_id')))->toBe([$ada->getKey()])
        ->and(array_unique(array_column(auditEvents(), 'site')))->toBe(['default']);
});

it('records nothing for an action that refuses', function (): void {
    $parent = auditSave(['title' => 'Parent'], locale: 'fr');
    auditSave(['title' => 'Child', 'parent' => $parent->id], locale: 'fr');

    expect(fn () => resolve(TrashEntry::class)->handle($parent))->toThrow(ValidationException::class);

    expect(auditEvents())->toBe([]);
});

it('records a Media deletion with its name', function (): void {
    Storage::fake('local');
    $media = resolve(SaveMedia::class)->handle(fixture('media/pixel.png'));

    resolve(DeleteMedia::class)->handle($media, auditUser());

    expect(auditTrail())->toBe([['kami.media.deleted', $media->id, ['name' => 'pixel']]])
        ->and(auditEvents()[0]['subject_type'])->toBe('media');
});

it('records one event per row of a bulk action', function (): void {
    Kami::routes();
    $first = auditSave(['title' => 'First'], locale: 'fr');
    $second = auditSave(['title' => 'Second'], locale: 'fr');

    entriesPage(ListEntries::class, type: 'page')
        ->selectTableRecords([$first->id, $second->id])
        ->callAction(TestAction::make('delete')->table()->bulk());

    expect(collect(auditTrail())->sortBy(1)->values()->all())->toBe(collect([
        ['kami.entries.trashed', $first->id, []],
        ['kami.entries.trashed', $second->id, []],
    ])->sortBy(1)->values()->all())
        ->and(array_column(auditEvents(), 'actor'))->toBe(['user', 'user']);
});

it('records one summary event for a batch of core actions, while Content events fire for each item', function () {
    Event::fake([EntryPublished::class]);

    $result = AuditContext::batch('acme.import.completed', function (): string {
        $save = resolve(SaveEntry::class);
        $first = $save->handle(Page::class, ['title' => 'One', 'status' => 'published'], locale: 'fr');
        $save->handle(Page::class, ['title' => 'Two', 'status' => 'published'], locale: 'fr');
        resolve(TrashEntry::class)->handle($first);

        // A row the import gave up on: rolled back, so neither recorded nor counted.
        try {
            DB::transaction(function () use ($save): void {
                $save->handle(Page::class, ['title' => 'Three', 'status' => 'published'], locale: 'fr');

                throw new RuntimeException('Rejected row.');
            });
        } catch (RuntimeException) {
        }

        return 'done';
    }, ['source' => 'velox.csv']);

    expect($result)->toBe('done')
        ->and(auditTrail())->toBe([
            ['acme.import.completed', null, ['kami.entries.published' => 2, 'kami.entries.trashed' => 1, 'source' => 'velox.csv']],
        ])
        ->and(auditEvents()[0]['actor'])->toBe('cli');
    Event::assertDispatchedTimes(EntryPublished::class, 2);
});

it('records nothing for a batch whose callback throws', function () {
    expect(fn () => AuditContext::batch('acme.import.completed', function (): void {
        resolve(SaveEntry::class)->handle(Page::class, ['title' => 'One', 'status' => 'published'], locale: 'fr');

        throw new RuntimeException('The source went away.');
    }))->toThrow(RuntimeException::class);

    // The item stays, as without a batch: only the Audit log is grouped.
    expect(DB::table('kami_audit_events')->count())->toBe(0);
});

it('refuses a batch’s context before running it, and holds its counts to no limit', function () {
    $ran = false;

    expect(fn () => AuditContext::batch('acme.import.completed', function () use (&$ran): void {
        $ran = true;
    }, ['note' => str_repeat('a', 256)]))->toThrow(InvalidArgumentException::class);

    $context = array_fill_keys(array_map(fn (int $i): string => "key{$i}", range(1, 20)), 'x');
    AuditContext::batch('acme.import.completed', function (): void {
        resolve(RecordAuditEvent::class)->handle('acme.rows.imported');
    }, $context);

    expect($ran)->toBeFalse()
        ->and(auditTrail())->toEqual([['acme.import.completed', null, [...$context, 'acme.rows.imported' => 1]]]);
});

it('counts a batch inside another as one event of the outer one', function () {
    AuditContext::batch('acme.sync.completed', function (): void {
        AuditContext::batch('acme.import.completed', fn () => auditSave(['title' => 'One', 'status' => 'published'], locale: 'fr'));
        auditSave(['title' => 'Two', 'status' => 'published'], locale: 'fr');
    });

    expect(auditTrail())->toBe([
        ['acme.sync.completed', null, ['acme.import.completed' => 1, 'kami.entries.published' => 1]],
    ]);
});

it('dispatches AuditEventRecorded once the row commits, for a batch’s summary only', function (): void {
    Event::fake([AuditEventRecorded::class]);
    $ada = auditUser();

    resolve(RecordAuditEvent::class)->handle('acme.invoices.deleted', ['invoice', 42], ['number' => 'F-12'], $ada);
    AuditContext::batch('acme.import.completed', fn () => auditSave(['title' => 'One', 'status' => 'published'], locale: 'fr'));

    Event::assertDispatchedTimes(AuditEventRecorded::class, 2);
    Event::assertDispatched(AuditEventRecorded::class, fn (AuditEventRecorded $recorded): bool => $recorded->event === 'acme.invoices.deleted'
        && [$recorded->subjectType, $recorded->subjectId, $recorded->site, $recorded->context, $recorded->actor] === ['invoice', '42', null, ['number' => 'F-12'], $ada]);
    Event::assertDispatched(AuditEventRecorded::class, fn (AuditEventRecorded $recorded): bool => $recorded->event === 'acme.import.completed'
        && $recorded->context === ['kami.entries.published' => 1]);
});
