# Roles are declared in code, and every check targets a Permission

A site's Roles are declared in code, in `config/kami.php`, as a handle mapped to its list of Permissions (`'administrator' => ['kami.*']`). A list may use wildcards (`kami.entries.*.*`, `kami.*`) that are expanded at boot. A Role's label is a UI string, so it comes from the `kami-roles` translation file under its handle, as Content Type labels come from `kami-types`, not from the configuration (amended in #103). The admin assigns Roles to Users and never edits a Role. Assignments live in `kami_role_user`:

- `user_id`, typed like the host's User key, cascading when the User is deleted;
- `role`, the Role's handle, with no foreign key, like the `site` handle (ADR 0004).

A User can hold several Roles; their Permissions are the union of those Roles. There is no `site` column: Roles are per installation, and access per Site (V1) adds one later.

Every check, whether in Kami, in Filament or in the site's code, targets a Permission and never a Role. Kami offers no public `hasRole()`. Checks go through Laravel Gates and Policies in the core, which keeps it independent of Filament. Kami registers no global `Gate::before`, because that would also answer the host's own abilities.

The starter kit declares two Roles: `administrator` (`kami.*`) and `editor`. The editor gets all content, Media and Menus, but no `kami.users.manage`, no `force-delete` and no Redirects. The rule "checks target Permissions" comes from Velox and held there, but Velox's two fixed Roles and three Capabilities do not carry over (retrospective §2.6).

## Considered options

- **spatie/laravel-permission, optionally with Filament Shield** (the comparatif's suggested bricks). Rejected for four reasons:
  - Its value is Roles edited in the database. With Roles in code, its five tables would only copy the configuration, and that copy has to be resynced on every deploy.
  - Its tables are unprefixed and its `permission.php` config is one per application. It would clash with a host application that already uses it, which breaks the `kami_` prefix rule and the rule against global registration (ADR 0001).
  - Its pivots are morph pairs with no foreign key, which ADR 0005 rejected.
  - By default it registers a global `Gate::before`.
  
  Shield adds an option screen and puts a Filament dependency where the core must have none.
- **Roles edited in the admin.** This would let a client create a Role without a deploy. The cost is an extra subsystem:
  - a Permission matrix screen to maintain across Filament majors;
  - drift detection for Permissions that name a Content Type removed from code;
  - an anti-escalation rule, so that nobody grants Permissions they do not hold;
  - Roles that differ between environments and stay out of the tests.

  None of the three reference sites needs it, so it moves to V1. Choosing Roles in code trades editor convenience for security and simplicity (principles 1 and 6).

## Consequences

- Permission vocabulary:
  - per Content Type: `kami.entries.{type}.{action}`, where the action is one of `view`, `create`, `update`, `reorder`, `publish`, `share-preview`, `delete`, `restore` or `force-delete`;
  - Media: `kami.media.{view|create|update|delete}`;
  - plus `kami.menus.manage`, `kami.redirects.manage`, `kami.form-submissions.view`, `kami.health.view` (the dashboard's "Needs attention" widget, added in M4), `kami.audit.view` (the read-only Audit log screen, ADR 0022, added in #347) and `kami.users.manage`.

  Global Sets and Singletons are Entries, so they need no separate settings Permission. The tickets for Menus, Forms and Drafts may refine the Permissions outside Content Types.
- `reorder` covers both Position and Parent, form included, because moving an Entry changes URLs. The admin holds it on the server, whatever the form (ADR 0019). `publish` is kept apart from `update` and `force-delete` apart from `delete`, so editing, going live and losing data are separate rights.
- Kami's Policies implement every method Filament may call:
  - `viewAny`/`view` → `view`;
  - `create`/`replicate` → `create`;
  - `update` → `update`;
  - `reorder` → `reorder`;
  - `delete`/`deleteAny` → `delete`;
  - `restore`/`restoreAny` → `restore`;
  - `forceDelete`/`forceDeleteAny` → `force-delete`.

  So Kami works whether the host enables `strictAuthorization()` or not. Kami's tests run with strict authorization on. The starter kit's `AdminPanelProvider` enables it, so a site Resource without a policy fails loudly instead of opening, and `kami:doctor` warns when the panel carrying `KamiPlugin` lacks it.
- A User with no Role sees nothing of Kami. The starter kit's `User::canAccessPanel()` returns true only for a User holding a Kami Role, and `kami:doctor` reports, as information rather than a warning, how many Users with no Role would pass it (Velox friction A4). In a host application, those are often the host's own staff (kawaiidream.net's moderators), and they legitimately see nothing of Kami.
- The host's User model implements `KamiUser` with the `HasKamiRoles` trait. `kami:install` fails until the model of the panel's guard does. That model must also be `auth.providers.users.model`, which `kami_role_user`, authorship and `kami:install`'s first administrator point at: `kami:install` and `kami:doctor` fail on a panel whose guard signs in through another provider (#343). In the MVP, `HasKamiRoles` is the only supported implementation. A host cannot map its own roles (an enum column, SSO groups) onto Kami Roles. Because the interface and the trait change together, a host Role source can still arrive in V1 without breaking anyone.
- `kami:doctor` reports assignments whose Role handle is not declared.
- Every Role change goes through the public action `AssignRoles`, whoever makes it: the admin, a seeder, `kami:install`, `kami:user:grant`. It takes the full list of a User's Roles, in one transaction, and refuses a Role to grant that is not declared; one already held may stay. It does not authorise, and there is no anti-escalation rule: `kami.users.manage` is the top right while Roles live in code (amended in #348).
- The lockout guard: the last User holding `kami.users.manage` neither loses it through `AssignRoles` nor is deleted, which `HasKamiRoles`' `deleting` hook refuses. The guard refuses only a change that takes the right from its last holder, so a seeder may give `editor` while nobody holds it yet. It locks the holders' rows inside the transaction, so two concurrent revocations cannot both pass. A query-builder delete skips the hook. The refusal is recorded in the Audit log (ADR 0022). The CLI rescue is `kami:user:grant {email} {role}`, existing Users only, the email matched as ADR 0001 says; there is no `kami:user:revoke`, since revoking is not a rescue (amended in #348).
- MFA: `KamiPlugin` registers Filament's authenticator-app provider with recovery codes. A User holding `kami.users.manage` sets it up before any other admin page and cannot disable it while holding the Permission; every other User may, from Filament's profile page, which the plugin turns on when the host has none. Filament decides "MFA required" once per panel, at boot, so Kami marks it required and swaps Filament's middleware for one that redirects only the holders, on Filament's pages and on Kami's Preview route. As Filament's, it runs on page requests, not on Livewire updates: the set-up page is a Livewire component, so a persistent middleware would send its own requests back to it; a User given `kami.users.manage` mid-session is sent to set-up at the next page. The host's User implements Filament's `HasAppAuthentication` and `HasAppAuthenticationRecovery` through `HasKamiMfa`, which stores them in `kami_user_mfa`: `user_id` typed like the host's User key, primary and cascading, the secret and the hashed recovery codes encrypted with the app key. The host's `users` table gains no column, as it gains none for Roles. Rotating `APP_KEY` without `APP_PREVIOUS_KEYS` leaves every authenticator unreadable; `kami:user:reset-mfa`, which deletes a User's row and records `kami.mfa.reset`, is the rescue, and `kami:doctor` reports a panel that no longer requires Kami's provider or whose User model cannot store an authenticator (#353). Security over Simplicity: Filament's own trait would need two columns on `users` (amended in #351).
- Ownership (rights limited to Entries a User created) is V1. `created_by` already exists and every Policy receives the Entry, so adding an "own" rule changes neither the schema nor the API.
- Roles edited in the admin are V1. A second Role source merged into the same registry changes neither `kami_role_user` nor any check.
