# Users and roles

Users belong to your application: Kami adds no `users` table and no login of its own. It gives your User model Roles, declared in code, and checks every access against a Permission those Roles grant (ADR 0014). A User with no Role sees nothing of Kami.

## The user model

The model of the guard your panel signs Users in with implements `KamiUser`, with the `HasKamiRoles` trait:

```php
use Illuminate\Foundation\Auth\User as Authenticatable;
use Psyao\Kami\Users\HasKamiRoles;
use Psyao\Kami\Users\KamiUser;

class User extends Authenticatable implements KamiUser
{
    use HasKamiRoles;
}
```

Until it does, `kami:install` stops before changing anything, and `kami:doctor` reports an error naming the model and what to add.

A User's Roles are its rows in `kami_role_user`, which follow the User when it is deleted. `$user->kamiRoles()` lists their handles, read once per model instance.

## Roles

`config/kami.php` declares the Roles, each with the Permissions it grants:

```php
'roles' => [
    'administrator' => ['kami.*'],
],
```

`*` matches any part of a Permission: `kami.*` grants them all, `kami.entries.*.view` lets a Role read every type's Entries. A User holding several Roles gets all their Permissions. The `administrator` Role grants every Permission, `kami.users.manage` included.

Roles are edited in code and deployed, never in the admin.

`kami:doctor` warns about assignments whose handle `config/kami.php` does not declare: such a Role grants nothing. Declare it again, or delete its rows from `kami_role_user`.

## The first administrator

While no User holds a Role that grants `kami.users.manage`, `kami:install` gives the `administrator` Role to the User whose email you give it, after a confirmation in a terminal, or creates that User from the email, name and password you give it ([Installation](installation.md#the-first-user)). No Audit log row records the grant yet, only the command's output. A new User is created with your `auth.providers.users.model`, which needs `email`, `name` and `password` columns, as Laravel's has.

## Permissions

Every check targets a Permission, never a Role. Each declared Content Type has four, named after its handle:

| Permission | Lets a User |
| --- | --- |
| `kami.entries.{type}.view` | list and read the type's Entries |
| `kami.entries.{type}.create` | create an Entry |
| `kami.entries.{type}.update` | edit a Translation that is not visible: a Draft or a Scheduled one |
| `kami.entries.{type}.publish` | make a Translation visible, or edit one that is |

`kami.users.manage` lets a User manage the others. [Permissions](../reference/permissions.md) lists the whole vocabulary.

Editing a visible Translation changes what visitors see, so it takes `publish` as well as `update` (ADR 0019). A User with `update` alone edits Drafts and Scheduled Translations only.

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
