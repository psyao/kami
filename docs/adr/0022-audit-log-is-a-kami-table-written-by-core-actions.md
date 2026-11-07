# The Audit log is a Kami table written by core actions, with truncated IP addresses

The brief asks for an audit log of sensitive actions. No CMS of the comparatif ships one in its core, and Velox had none. Kami records security-sensitive events in its own table, `kami_audit_events`, written by the core actions. Every write already goes through them (ADR 0011), so they are the only place that knows an action's meaning: a publication, not an `updated` row. Eloquent model events are not used, because they would miss query-builder and bulk writes and would fire for every row of an import; the host's User is the one exception, below (amended in #348). The Audit log is not a history of content: that belongs to revisions (V1) and to the Last modifier.

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
- Event names are `kami.<area>.<past participle>`, the subject stored under a short type name (`entry`, `media`, `user`, `preview-link`) so a renamed class never orphans a row (amended in #342):
  - `kami.entries.published` when a Translation's status turns `published`, a Scheduled one included; `kami.entries.unpublished` when it turns back to `draft`; `kami.entries.publication-date-changed` when a published Translation's date moves (context: `locale`, and `from` and `to` for the date);
  - `kami.entries.trashed`, `kami.entries.restored`, `kami.entries.force-deleted`;
  - `kami.preview-links.created`, `kami.preview-links.revoked` (context: `entry`, `locale`, `expires_at`); a link deleted with its Translation or Entry records nothing of its own;
  - `kami.media.deleted` (context: `name`);
  - `kami.content.migrated`, one per `ContentMigrator` operation (context: `operation` and its arguments).
  - `kami.roles.granted` and `kami.roles.revoked`, one per Role, from `AssignRoles` (context: `role`); `kami.users.lockout-refused` when the lockout guard refuses (context: `operation`, `revoke` or `delete`), written after the action's transaction and again if a caller's transaction rolls it back, since the refusal is the event (amended in #348);
  - `kami.users.created` and `kami.users.deleted`, from model hooks `HasKamiRoles` boots on the host's User, the signed-in User as actor. Users are the host's, so no core action creates or deletes them, and a hook is Kami's only door to them: Security (principle 1) wins over the rule against Eloquent events, which still holds for Kami's own models. A query-builder delete skips the hooks, which the guide says (amended in #348);
  - `kami.auth.password-reset`, from Laravel's `PasswordReset` event, the User as subject and actor. The event carries no guard, so the core records it for a `KamiUser`, the model `kami:doctor` requires of a Kami panel's guard (amended in #350);
  - `kami.auth.signed-in` and `kami.auth.sign-in-failed`, from Laravel's `Login` and `Failed` events on a Kami panel's guard, for a `KamiUser`: the User as subject, and as actor for a sign-in only, since whoever typed a wrong password is unknown; a failure with no User, an unknown address, records nothing. `kami.auth.locked-out`, from the `Lockout` event Kami's sign-in page fires once per throttling window, with no subject and the throttled key's kind as context (`key`: `ip` or `email`), never the address: the event carries no guard, so the core records only the lockouts that page fires, and a window's later refusals record nothing, so refused requests cannot fill the table (amended in #349);
  - `kami.mfa.enabled`, `kami.mfa.disabled` and `kami.mfa.recovery-used`, from `HasKamiMfa` inside Filament's transaction, the User as subject and actor (amended in #351); `kami.mfa.reset`, from `kami:user:reset-mfa`, the User as subject and the actor `cli` (amended in #353).
- A core action run inside `AuditContext::batch($event, fn () => …, $context)` records nothing per item. The batch records one summary event, such as `kami.import.completed` with its source and the count of each event, with `actor: cli` in the console. A rolled back item is not counted. Imports run inside it, so a run of thousands of Entries writes one row. `ContentMigrator` writes with the query builder, calling no core action, so each operation records its one `kami.content.migrated` directly (amended in #342).
- Recording is a side effect inside the actions, not a listener of Kami's public events (ADR 0029), so a site cannot unregister it. `AuditContext::batch()` only groups Audit events; the public events still fire for every item.
- Site code records its own events through the public action `RecordAuditEvent`.
- The admin shows a read-only `AuditEventResource`, filterable by User, event and date, behind a new Permission `kami.audit.view` (ADR 0014). The starter kit's `administrator` holds it through `kami.*`; `editor` does not. Nothing in the admin edits or deletes an event: only retention removes them.
- Events are per installation, like Users and Roles (ADR 0004).
- Shared hosting and server behave the same: one insert inside the action's transaction, and pruning through the Tick (ADR 0020).
