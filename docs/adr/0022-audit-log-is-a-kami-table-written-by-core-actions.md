# The Audit log is a Kami table written by core actions, with truncated IP addresses

The brief asks for an audit log of sensitive actions. No CMS of the comparatif ships one in its core, and Velox had none. Kami records security-sensitive events in its own table, `kami_audit_events`, written by the core actions. Every write already goes through them (ADR 0011), so they are the only place that knows an action's meaning: a publication, not an `updated` row. Eloquent model events are not used, because they would miss query-builder and bulk writes and would fire for every row of an import. The Audit log is not a history of content: that belongs to revisions (V1) and to the Last modifier.

Columns:

- `id` (ULID) and `occurred_at` (UTC);
- `user_id`, nullable, typed like the host's User key, with no foreign key, so an event outlives its User; the admin shows a deleted User as "Deleted User (id)";
- `actor`: `user`, `cli` or `system`;
- `event`, such as `kami.entries.published`;
- `subject_type` and `subject_id`, with no foreign key, so a force-deleted Entry's events remain;
- `site`, nullable;
- `ip`, truncated to /24 for IPv4 and /48 for IPv6;
- `context`: a short JSON object, such as the Locale or the old and new Role, never a content diff.

There is no user agent. Events are kept 12 months by default (`kami.audit.retention`) and pruned by the Scheduled task `kami:audit:prune`.

The truncated IP trades part of security's forensic value (principle 1) for privacy (principle 2). A /24 or /48 still shows the network and the region of a suspicious sign-in, and no longer identifies a single visitor's connection.

## Considered options

- **spatie/laravel-activitylog** (the comparatif's brick; MIT, maintained). It requires PHP 8.4. Its `activity_log` table has two polymorphic pairs without foreign keys and an unprefixed name that a host application may already use (ADRs 0001 and 0005). It also registers a global `activity()` helper. It is built to trace model changes, which is the wrong grain here. Rejected under principles 5 and 8.
- **Full IP addresses for 90 days.** More useful in an investigation, but it stores personal data of every User at every sign-in.
- **No IP address at all.** It leaves a stolen-credential sign-in with nothing to compare.
- **A hash chain against tampering.** Anyone who can rewrite the table already holds the database and the application key. It adds a cost on every write for no protection that matters on one installation.

## Consequences

- Events recorded:
  - **Accounts**: successful sign-in; failed sign-in for an existing account (never the typed email of an unknown account, never a password); throttling lockout; password reset; MFA enabled, disabled or reset (including `kami:user:reset-mfa`).
  - **Rights**: Role granted or revoked (including `kami:user:grant`); lockout-guard refusal (ADR 0014); User created or deleted.
  - **Visibility**: publish, unpublish, and a changed publication date on a visible or Scheduled Translation (ADR 0019); trash, restore and force-delete; Preview link created or revoked.
  - **Sensitive data**: Redirect changes, Media deletion, and Submissions exported or deleted.
  
  Saving a Draft is not recorded, and neither is reading Submissions.
- A core action run inside `AuditContext::batch(fn () => …)` records nothing per item. The batch records one summary event, such as `kami.import.completed` with its source and counts, with `actor: cli`. Imports and `ContentMigrator` run inside it, so a run of thousands of Entries writes one row.
- Recording is a side effect inside the actions, not a listener of Kami's public events (ADR 0029), so a site cannot unregister it. `AuditContext::batch()` only groups Audit events; the public events still fire for every item.
- Site code records its own events through the public action `RecordAuditEvent`.
- The admin shows a read-only `AuditEventResource`, filterable by User, event and date, behind a new Permission `kami.audit.view` (ADR 0014). The starter kit's `administrator` holds it through `kami.*`; `editor` does not. Nothing in the admin edits or deletes an event: only retention removes them.
- Events are per installation, like Users and Roles (ADR 0004).
- Shared hosting and server behave the same: one insert inside the action's transaction, and pruning through the Tick (ADR 0020).
