<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Psyao\Kami\Admin\Internal\AuditEventResource;
use Psyao\Kami\Admin\Internal\AuditEventResource\ListAuditEvents;
use Psyao\Kami\Admin\Internal\EntryResource;
use Psyao\Kami\Audit\Actions\RecordAuditEvent;
use Psyao\Kami\Audit\Internal\AuditEvent;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Actions\TrashEntry;
use Workbench\App\Content\Article;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

/** The Audit log in the admin, for a User holding the Role, or these Permissions under a Role of their own. */
function auditLog(string | array $role = 'administrator'): Testable
{
    signInWith($role);
    Filament::setCurrentPanel('admin');

    return Livewire::test(ListAuditEvents::class);
}

function auditRecord(string $event, mixed $subject = null, array $context = [], mixed $actor = null): AuditEvent
{
    resolve(RecordAuditEvent::class)->handle($event, $subject, $context, $actor);

    return AuditEvent::query()->latest('id')->firstOrFail();
}

it('takes kami.audit.view, which administrator holds and the Workbench editor does not', function () {
    signInWith('editor');
    $this->get('/admin/audit-events')->assertForbidden();

    signInWith(['kami.audit.view']);
    $this->get('/admin/audit-events')->assertOk();

    signInWith('administrator');
    $this->get('/admin/audit-events')->assertOk();
});

it('reads each row on its own: when in the Site’s time zone, who, what, on which subject, the Site, the IP and the context, newest first', function () {
    config(['kami.sites.default.timezone' => 'Europe/Zurich']);
    $ada = auditUser();
    $entry = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Hello'], actor: $ada);
    $this->travelTo('2026-07-01 08:00:00');
    $published = auditRecord('kami.entries.published', $entry, ['locale' => 'en', 'featured' => true], $ada);
    $this->travelTo('2026-07-01 09:00:00');
    $cli = auditRecord('acme.cache.cleared');
    $this->travelBack();

    auditLog()
        ->assertCanSeeTableRecords([$cli, $published], inOrder: true)
        ->assertTableColumnFormattedStateSet('occurred_at', 'Jul 1, 2026 10:00:00', $published)
        ->assertTableColumnStateSet('actor', 'Ada Lovelace', $published)
        ->assertTableColumnStateSet('actor', 'Command line', $cli)
        ->assertTableColumnFormattedStateSet('event', 'Entry published', $published)
        ->assertTableColumnFormattedStateSet('event', 'acme.cache.cleared', $cli)
        ->assertTableColumnStateSet('subject', 'Entry: Hello', $published)
        ->assertTableColumnStateSet('site', 'default', $published)
        ->assertTableColumnStateSet('context', 'featured: true, locale: en', $published)
        ->assertSeeHtml(EntryResource::withConfiguration('article', fn (): string => EntryResource::editUrl($entry, null)));
});

it('names a deleted User by id, and shows a subject gone, or out of the User’s reach, by its id', function () {
    $gone = auditUser();
    $goneId = $gone->getKey();
    $entry = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Secret']);
    $granted = auditRecord('kami.roles.granted', $gone, ['role' => 'editor'], $gone);
    $published = auditRecord('kami.entries.published', $entry, ['locale' => 'en']);
    $forgotten = auditRecord('kami.entries.force-deleted', ['entry', '01ARZ3NDEKTSV4RRFFQ69G5FAV']);
    User::withoutEvents(fn () => $gone->delete());

    auditLog(['kami.audit.view'])
        ->assertTableColumnStateSet('actor', "Deleted User ({$goneId})", $granted)
        ->assertTableColumnStateSet('subject', "User: Deleted User ({$goneId})", $granted)
        ->assertTableColumnStateSet('subject', "Entry: {$entry->id}", $published)
        ->assertTableColumnStateSet('subject', 'Entry: 01ARZ3NDEKTSV4RRFFQ69G5FAV', $forgotten)
        ->assertDontSeeHtml('Secret');
});

it('titles an Entry in the Trash, marked, with no link: it has no edit page', function () {
    $entry = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Hello']);
    resolve(TrashEntry::class)->handle($entry);
    $trashed = AuditEvent::query()->where('event', 'kami.entries.trashed')->sole();

    auditLog()
        ->assertTableColumnStateSet('subject', 'Entry: Hello (in the Trash)', $trashed)
        ->assertDontSeeHtml(EntryResource::withConfiguration('article', fn (): string => EntryResource::editUrl($entry, null)));
});

it('filters by a User acting or acted upon, deleted ones included, by event and by days of the Site’s time zone', function () {
    config(['kami.sites.default.timezone' => 'Europe/Zurich']);
    $ada = auditUser();
    $grace = auditUser();
    $this->travelTo('2026-07-01 22:30:00'); // 2 July in Zurich
    $byAda = auditRecord('acme.invoices.exported', actor: $ada);
    $this->travelTo('2026-07-03 10:00:00');
    $onAda = auditRecord('kami.auth.sign-in-failed', $ada);
    $byGrace = auditRecord('kami.roles.granted', $grace, ['role' => 'editor'], $grace);
    $this->travelBack();
    $graceId = (string) $grace->getKey();
    User::withoutEvents(fn () => $grace->delete());

    auditLog(['kami.audit.view'])
        ->assertTableFilterExists('user', fn (SelectFilter $filter): bool => ($filter->getOptions()[$graceId] ?? null) === "Deleted User ({$graceId})")
        ->filterTable('user', (string) $ada->getKey())
        ->assertCanSeeTableRecords([$byAda, $onAda])
        ->assertCanNotSeeTableRecords([$byGrace])
        ->resetTableFilters()
        ->filterTable('event', 'kami.roles.granted')
        ->assertCanSeeTableRecords([$byGrace])
        ->assertCanNotSeeTableRecords([$byAda, $onAda])
        ->resetTableFilters()
        ->filterTable('occurred_at', ['from' => '2026-07-02', 'until' => '2026-07-02'])
        ->assertCanSeeTableRecords([$byAda])
        ->assertCanNotSeeTableRecords([$onAda, $byGrace]);
});

it('offers no way to create, edit or delete an event', function () {
    auditRecord('acme.cache.cleared');

    auditLog()
        ->assertActionDoesNotExist('create')
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete')
        ->assertTableBulkActionDoesNotExist('delete');

    expect(AuditEventResource::getPages())->toHaveKeys(['index'])->toHaveCount(1);
});
