# Users and roles

Users belong to your application: Kami adds no `users` table and no login of its own. It gives your User model Roles, declared in code, and checks every access against a Permission those Roles grant (ADR 0014). A User with no Role sees nothing of Kami.

## The user model

The model of the guard your panel signs Users in with implements `KamiUser`, with the `HasKamiRoles` trait:

```php
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Psyao\Kami\Admin\HasKamiMfa;
use Psyao\Kami\Users\HasKamiRoles;
use Psyao\Kami\Users\KamiUser;

class User extends Authenticatable implements HasAppAuthentication, HasAppAuthenticationRecovery, KamiUser
{
    use HasKamiMfa;
    use HasKamiRoles;
}
```

`HasKamiMfa` stores the User's authenticator for [MFA](#mfa); a model without it, or without Filament's two contracts, makes the sign-in page fail, and `kami:doctor` reports an error.

Until it does, `kami:install` stops before changing anything, and `kami:doctor` reports an error naming the model and what to add.

That model is also `auth.providers.users.model`: `kami_role_user`, the authorship of Entries and `kami:install`'s first administrator all point at it. A panel whose guard signs in through another provider would give Roles to Users nobody signs in as, so `kami:install` stops and `kami:doctor` reports an error naming both models. A Role source of the host's own lifts this in a later version.

A User's Roles are its rows in `kami_role_user`, which follow the User when it is deleted. `$user->kamiRoles()` lists their handles, read once per model instance.

`HasKamiRoles` also adds model hooks to your User: creating or deleting a User through Eloquent records it in the [Audit log](audit-log.md), and deleting the last User who may manage Users is refused ([The lockout guard](#the-lockout-guard)). A query-builder delete, such as `User::query()->where(...)->delete()`, skips them, as it skips every Eloquent event: it records nothing and is not guarded. Until `kami:install` has created the Audit log's table, the hooks do nothing.

## Roles

`config/kami.php` declares the Roles, each with the Permissions it grants:

```php
'roles' => [
    'administrator' => ['kami.*'],
],
```

`*` matches any part of a Permission: `kami.*` grants them all, `kami.entries.*.view` lets a Role read every type's Entries. A User holding several Roles gets all their Permissions. The `administrator` Role grants every Permission, `kami.users.manage` included.

Roles are edited in code and deployed, never in the admin.

## Giving Roles

Every change to a User's Roles goes through the `AssignRoles` action, whoever makes it: the admin, `kami:install`, `kami:user:grant` or your own code, a seeder for instance. It takes the full list of Roles the User should hold, so a Role left out is revoked:

```php
use Psyao\Kami\Users\Actions\AssignRoles;

resolve(AssignRoles::class)->handle($user, ['editor'], actor: auth()->user());
```

It runs in one transaction and records each Role granted or revoked in the [Audit log](audit-log.md). Once the change commits, it dispatches `Psyao\Kami\Users\Events\RolesAssigned`, with `user`, the `granted` and `revoked` handles and `actor`; a call that changes nothing dispatches nothing (ADR 0029). It refuses, with a `ValidationException` on `roles` and changing nothing:

- a Role to grant that `config/kami.php` does not declare, so a typo never grants nothing silently. A Role the User already holds may stay undeclared;
- a change the [lockout guard](#the-lockout-guard) refuses.

Like Kami's other actions, it does not authorise: code calling it from a request checks `kami.users.manage` first. There is no rule against escalation: a User who may manage Users may give any Role, `administrator` included.

Writing to `kami_role_user` yourself skips the guard and the Audit log. Shared hosting and a server behave the same: one transaction, nothing waiting on a process.

## The lockout guard

The last User who may manage Users neither loses `kami.users.manage` nor is deleted, so a site never locks itself out of its admin. `AssignRoles` refuses the change and the User's `deleting` hook refuses the deletion, each with a message saying why, and records `kami.users.lockout-refused`. Give `kami.users.manage` to another User first.

The guard only refuses a change that would take the right from its last holder: while nobody holds it yet, before `kami:install` for instance, a seeder may give `editor` to a User or delete one. Inside a transaction, the holders' rows stay locked until it ends, so two Users cannot take the right from each other at once. `AssignRoles` opens its own; `$user->delete()` opens none, so delete a User inside `DB::transaction()` to keep two deletions of the last two managers from both passing.

## From the command line

`kami:user:grant` gives a Role to an existing User, to rescue a site nobody can manage any more:

```bash
php artisan kami:user:grant ada@example.com administrator
```

It finds the User by email as `kami:install` does: case-insensitively, and several Users matching the address make it fail. It never creates a User, and refuses an unknown email and an undeclared Role. It takes no password. The grant goes through `AssignRoles` and is recorded with the actor `cli`. There is no command to revoke a Role: revoking is not a rescue, and the admin does it.

`kami:user:reset-mfa` clears a User's authenticator and recovery codes, for a User who lost both:

```bash
php artisan kami:user:reset-mfa ada@example.com
```

It finds the User by email as `kami:user:grant` does, and takes no password. The User sets MFA up again at the next sign-in, at once when it may manage Users. The reset is recorded in the [Audit log](audit-log.md) as `kami.mfa.reset`, with the actor `cli`. A User with no MFA set up is left as it is.

`kami:doctor` warns about assignments whose handle `config/kami.php` does not declare: such a Role grants nothing. Declare it again, or delete its rows from `kami_role_user`.

## MFA

`KamiPlugin` adds Filament's authenticator-app second factor to your panel, with recovery codes. The code is asked at every sign-in, after the password.

- A User whose Roles grant `kami.users.manage` must set it up before reaching any other admin page: Filament's set-up page shows a QR code, the secret as text, and asks a code before enabling it. It then shows eight recovery codes, once; each signs in once in place of a code. That User cannot disable MFA while holding the Permission, only regenerate the codes.
- Every other User may set it up, or disable it, from the profile page. `KamiPlugin` turns on Filament's profile page (`->profile()`) when your panel has none; your own `->profile(MyProfile::class)` wins.

`HasKamiMfa` keeps the authenticator in `kami_user_mfa`, one row per User with MFA, deleted with the User: your `users` table gains no column. The secret and the hashed recovery codes are encrypted with the app key. Setting MFA up, disabling it and using a recovery code land in the [Audit log](audit-log.md) as `kami.mfa.enabled`, `kami.mfa.disabled` and `kami.mfa.recovery-used`; a reset from the [command line](#from-the-command-line) as `kami.mfa.reset`. Filament remembers the last code accepted in your cache, so a code signs in once; the `file` and `database` stores of a shared host do.

Rotating `APP_KEY` without listing the old key in `APP_PREVIOUS_KEYS` makes every authenticator unreadable, and its Users cannot sign in. See [Security](security.md#mfa).

`kami:doctor` counts, as a warning, the Users who may manage Users and have not set MFA up yet: the panel stops each at the next sign-in until it does.

A model may implement Filament's two contracts on columns of its own instead of using `HasKamiMfa`; it then records no MFA event, and `kami:user:reset-mfa`, which clears `kami_user_mfa` only, finds no MFA to reset.

## The first administrator

While no User holds a Role that grants `kami.users.manage`, `kami:install` gives the `administrator` Role to the User whose email you give it, after a confirmation in a terminal, or creates that User from the email, name and password you give it, or prints a one-time link for them to set the password ([Installation](installation.md#the-first-user)). The grant goes through `AssignRoles` and is recorded in the Audit log with the actor `cli`. A new User is created with your `auth.providers.users.model`, which needs `email`, `name` and `password` columns, as Laravel's has. The email is compared case-insensitively, and several Users matching it make the command fail; sign-in stays your application's, case-sensitive on SQLite and PostgreSQL.

## Permissions

Every check targets a Permission, never a Role. Each declared Content Type has eight, named after its handle:

| Permission | Lets a User |
| --- | --- |
| `kami.entries.{type}.view` | list and read the type's Entries |
| `kami.entries.{type}.create` | create an Entry, [duplicate](content-types.md#duplicating-an-entry) one |
| `kami.entries.{type}.update` | edit a Draft |
| `kami.entries.{type}.reorder` | reorder the rows of an [orderable type](content-types.md#types-ordered-by-hand), change the Parent of an Entry of a [hierarchical type](content-types.md#hierarchical-types) |
| `kami.entries.{type}.publish` | publish or unpublish a Translation, or edit a Published or Scheduled one |
| `kami.entries.{type}.delete` | move an Entry to the [Trash](content-types.md#the-trash), delete a [Translation never published](content-types.md#deleting-a-translation) |
| `kami.entries.{type}.restore` | restore an Entry from the Trash; one with a published Translation takes `publish` too |
| `kami.entries.{type}.force-delete` | [delete an Entry for good](content-types.md#deleting-for-good) from the Trash |
| `kami.entries.{type}.share-preview` | create, list and revoke a Translation's [Preview links](publishing.md#preview-links) |

The [Media library](media.md#the-library-in-the-admin) has four, shared by every Content Type:

| Permission | Lets a User |
| --- | --- |
| `kami.media.view` | list the library |
| `kami.media.create` | upload files to it, from the library or from a Media Field |
| `kami.media.update` | edit a Media item's alt text, caption, credit and file |
| `kami.media.delete` | [delete a Media item](media.md#deleting-a-media-item) no Entry uses |

`kami.health.view` shows the dashboard's **Needs attention** widget, the last [`kami:doctor`](installation.md#checking-the-environment) result. `kami.users.manage` lets a User manage the others. `kami.audit.view` lets a User read the [Audit log](audit-log.md#reading-the-log), and nothing more. [Permissions](../reference/permissions.md) lists the whole vocabulary.

Editing a Published or Scheduled Translation changes what visitors see, now or at its date, so it takes `publish` as well as `update` (ADR 0019). A User with `update` alone edits Drafts only.

Being offered a link takes `view` on the target type: a [relation Field](content-types.md#relations)'s picker and the Parent input search and title only Entries of types the User may view, and creating a target from a picker takes `create` and `view`. A target of another type, already picked, shows as a restricted Entry with no title, which the User may remove; a save keeps it otherwise. The lists of what links to an Entry or uses a Media item, in the Trash's warning, the Media item's Used by and `DeleteMedia`'s refusal, count the Entries of those types instead of naming them.

A [bulk action](content-types.md#bulk-actions) checks these on each row as the row's own action would, so selecting rows grants nothing more.

Each Permission is a Laravel Gate, so your own code checks it the usual way:

```php
$user->can('kami.entries.article.publish');

Gate::authorize('kami.users.manage');
```

Kami registers no `Gate::before`: your application's own abilities answer as before, even for an administrator. `SaveEntry` checks nothing itself ([Publishing](publishing.md#saveentry-does-not-authorise)): code that calls it from a request checks these Permissions first.

## Strict authorization

Kami's admin checks every action through its policies. Turn on Filament's strict authorization on the panel carrying `KamiPlugin`, so that a Resource of yours without a policy fails loudly instead of opening to every User who signs in:

```php
->strictAuthorization()
```

`kami:doctor` warns about a Kami panel without it.

## Panel access

Who may sign in to the panel stays your User model's `canAccessPanel()`. A site whose only Users are its editors admits those holding a Kami Role:

```php
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;

class User extends Authenticatable implements FilamentUser, KamiUser
{
    use HasKamiRoles;

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->kamiRoles() !== [];
    }
}
```

The starter kit's User does exactly this. In an application with its own staff, `canAccessPanel()` may admit Users with no Kami Role: they sign in and see nothing of Kami. `kami:doctor` counts them as an information, which never fails it, not even with `--strict`.
