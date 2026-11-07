# Audit log

Kami records security-sensitive actions in its own table, `kami_audit_events`: who changed what visitors see, who deleted what, and when. It is a record for investigating an incident, not a history of content (ADR 0022).

## What is recorded

Each core action writes its row inside its own transaction, so a rolled back action leaves none, and a recorded one really happened. An action that refuses, with a `ValidationException` for instance, records nothing, but for the lockout guard's refusal, which is the event.

| Event | When | Context |
| --- | --- | --- |
| `kami.entries.published` | A Translation's status turns `published`, with a future date (Scheduled) too | `locale` |
| `kami.entries.unpublished` | A published Translation turns back to `draft` | `locale` |
| `kami.entries.publication-date-changed` | A published or Scheduled Translation's date moves | `locale`, `from`, `to` |
| `kami.entries.trashed` | `TrashEntry` | |
| `kami.entries.restored` | `RestoreEntry` | |
| `kami.entries.force-deleted` | `DeleteEntry` | |
| `kami.preview-links.created` | A Preview link is created | `entry`, `locale`, `expires_at` |
| `kami.preview-links.revoked` | A Preview link is revoked | `entry`, `locale`, `expires_at` |
| `kami.media.deleted` | `DeleteMedia` | `name` |
| `kami.content.migrated` | Each `ContentMigrator` operation | `operation` and its arguments |
| `kami.roles.granted` | `AssignRoles` gives a User a Role; the User is subject | `role` |
| `kami.roles.revoked` | `AssignRoles` takes a Role from a User; the User is subject | `role` |
| `kami.users.lockout-refused` | The [lockout guard](users-and-roles.md#the-lockout-guard) refuses a change; the User is subject. Recorded even when the caller's transaction rolls back | `operation`: `revoke` or `delete` |
| `kami.users.created` | A User is created through Eloquent; the signed-in User is the actor | |
| `kami.users.deleted` | A User is deleted through Eloquent; the signed-in User is the actor | |
| `kami.auth.password-reset` | A User completes a password reset, Laravel's `PasswordReset` event for a `KamiUser`; the User is subject and actor | |
| `kami.mfa.enabled` | A User sets [MFA](users-and-roles.md#mfa) up through `HasKamiMfa`; the User is subject and actor | |
| `kami.mfa.disabled` | A User disables MFA through `HasKamiMfa`; the User is subject and actor | |
| `kami.mfa.recovery-used` | A User signs in with a recovery code through `HasKamiMfa`; the User is subject and actor | |
| `kami.mfa.reset` | `kami:user:reset-mfa` clears a User's authenticator; the User is subject, the actor `cli` | |

A bulk action in the admin records one event per row, as each row's own action does.

Each row holds:

- `occurred_at`, in UTC;
- `user_id`, the actor passed to the action, with no foreign key: an event outlives its User;
- `actor`: `user` when an actor was passed, `cli` in the console without one, `system` otherwise;
- `event`;
- `subject_type` and `subject_id`, with no foreign key, so a force-deleted Entry's events remain. Kami's subjects use short type names (`entry`, `media`, `user`, `preview-link`), so a renamed class never orphans a row;
- `site`, from the subject;
- `ip`, truncated (see [Privacy](#privacy));
- `context`, a short flat JSON object.

## What is not recorded

- Saving a Draft, or saving a published Translation without moving its date: the log is about what visitors see, not every keystroke.
- A Preview link deleted with its Translation (`DeleteTranslation`) or its Entry (`DeleteEntry`): the deletion itself is the event.
- Content: no title, no body, no diff. Revisions are a V1 feature.
- A `ContentMigrator` operation in a site migration older than Kami's `kami_audit_events` table: on a fresh `migrate` it runs before the table exists.
- Writes that bypass the core actions, with the query builder or Eloquent directly. Every write goes through a core action (ADR 0011). Users are the host's: their creation and deletion are recorded by `HasKamiRoles`' model hooks, which a query-builder write skips.

Recording is part of the actions, not a listener of Kami's Content events, so a site cannot unregister it by accident.

## Privacy

The log stores as little personal data as it can:

- The IP is truncated to its network: `/24` for IPv4 (`203.0.113.42` is stored as `203.0.113.0`), `/48` for IPv6. It still shows the network and region of a suspicious action, not one visitor's connection. Outside an HTTP request, from the command line or a queue, it is null.
- No user agent is stored.
- The context never holds content.

## Recording your own events

A site records its own sensitive actions through the public `RecordAuditEvent` action, in the same transaction as the write it records:

```php
use Illuminate\Support\Facades\DB;
use Psyao\Kami\Audit\Actions\RecordAuditEvent;

DB::transaction(function () use ($invoice, $user): void {
    $invoice->delete();
    resolve(RecordAuditEvent::class)->handle('acme.invoices.deleted', $invoice, ['number' => $invoice->number], $user);
});
```

- **Event**: a dotted name. Names starting with `kami.` are Kami's own.
- **Subject**: a model, or its type and id once it is gone (`['invoice', 42]`). A model of your own is stored under its morph class, so give it an alias with `Relation::morphMap()` to survive a rename. The Site comes from the model's `site` attribute when it has one.
- **Context**: a flat array of at most 20 keys, each value a scalar or null, strings up to 255 characters. Anything else throws an `InvalidArgumentException` and records nothing.
- **Actor**: the User who acted, or null for the command line or the system.

Every recorded row dispatches `Psyao\Kami\Audit\Events\AuditEventRecorded` once its transaction commits, with the row's values and the actor, so a site can alert on an event or forward it to another system (ADR 0029).

## Batches

An import or a migration over thousands of Entries would flood the log with one row per item. Run it inside `AuditContext::batch()`:

```php
use Psyao\Kami\Audit\AuditContext;

AuditContext::batch('acme.import.completed', function () use ($rows): void {
    foreach ($rows as $row) {
        resolve(SaveEntry::class)->handle(Article::class, $row);
    }
}, ['source' => 'velox.csv']);
```

Your context follows `RecordAuditEvent`'s rules and is checked before the callback runs; the counts are not held to its 20 keys. Inside the callback, `RecordAuditEvent` records nothing per item. Once it returns, the batch records one summary event with your context and the count of each event, such as `{"source": "velox.csv", "kami.entries.published": 1200}`. An item rolled back inside the batch is not counted; a callback that throws records no summary. A batch inside another counts as one event of the outer one. `batch()` returns what the callback returns.

Only the Audit log is grouped: the [Content events](publishing.md#reacting-to-content) still fire for every item, so your listeners see each change.

## Shared hosting and server

Both behave the same: one insert inside the action's transaction, no queue, no cron, no extension.
