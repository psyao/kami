<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Workbench\App\Filament\UserResource\CreateUser;
use Workbench\App\Filament\UserResource\EditUser;
use Workbench\App\Filament\UserResource\ListUsers;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

/** A User holding these Roles, created without model events: a fixture, whose `kami.users.created` row no test reads. */
function userWith(string $name, string ...$roles): User
{
    $user = User::withoutEvents(fn () => User::forceCreate(['name' => $name, 'email' => Str::lower($name) . '@example.test', 'password' => 'secret']));
    DB::table('kami_role_user')->insert(array_map(fn (string $role): array => ['id' => (string) Str::ulid(), 'user_id' => $user->getKey(), 'role' => $role], $roles));

    return $user;
}

/** A page of the Workbench Users screen, for a User holding the Role, or these Permissions under a Role of their own. */
function usersPage(string $page, string | array $role = 'administrator', array $parameters = []): Testable
{
    signInWith($role);
    Filament::setCurrentPanel('admin');

    return Livewire::test($page, $parameters);
}

it('takes kami.users.manage, which administrator holds and the Workbench editor does not', function () {
    $grace = userWith('Grace', 'editor');

    signInWith('editor');
    $this->get('/admin/users')->assertForbidden();
    $this->get("/admin/users/{$grace->id}/edit")->assertForbidden();

    signInWith(['kami.users.manage']);
    $this->get('/admin/users')->assertOk();
    $this->get('/admin/users/create')->assertOk();
    $this->get("/admin/users/{$grace->id}/edit")->assertOk();
});

it('lists every User with the labels of their Roles', function () {
    $grace = userWith('Grace', 'editor', 'administrator');
    $linus = userWith('Linus');

    usersPage(ListUsers::class)
        ->assertCanSeeTableRecords([$grace, $linus])
        ->assertTableColumnStateSet('roles', ['Administrator', 'Editor'], $grace)
        ->assertTableColumnStateSet('roles', null, $linus)
        ->searchTable('grace@')
        ->assertCanSeeTableRecords([$grace])
        ->assertCanNotSeeTableRecords([$linus]);
});

it('creates a User with Roles and no password of anyone’s choosing, offering a one-time link to set it', function () {
    usersPage(CreateUser::class)
        ->fillForm(['name' => 'Grace', 'email' => 'grace@example.test', 'kami_roles' => ['editor']])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Send Grace this link to set their password');

    $grace = User::query()->where('email', 'grace@example.test')->sole();
    expect($grace->kamiRoles())->toBe(['editor'])
        ->and(DB::table('password_reset_tokens')->where('email', 'grace@example.test')->exists())->toBeTrue()
        ->and(collect(auditEvents())->pluck('event')->all())->toBe(['kami.users.created', 'kami.roles.granted']);
});

it('gives and takes away Roles, each change in the Audit log under the User who made it', function () {
    $grace = userWith('Grace', 'editor');
    $page = usersPage(EditUser::class, parameters: ['record' => $grace->getKey()]);
    $actor = auth()->user();

    $page->assertSchemaStateSet(['kami_roles' => ['editor']])
        ->fillForm(['kami_roles' => ['administrator']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($grace->fresh()?->kamiRoles())->toBe(['administrator'])
        ->and(collect(auditEvents())->map(fn (array $row): array => [$row['event'], $row['context'], $row['user_id']])->all())->toEqual([
            ['kami.roles.granted', ['role' => 'administrator'], $actor?->getAuthIdentifier()],
            ['kami.roles.revoked', ['role' => 'editor'], $actor?->getAuthIdentifier()],
        ]);
});

it('refuses taking kami.users.manage from the last User holding it, on the field, saving nothing', function () {
    $ada = signInWith('administrator');
    Filament::setCurrentPanel('admin');

    Livewire::test(EditUser::class, ['record' => $ada->getKey()])
        ->fillForm(['name' => 'Ada renamed', 'kami_roles' => ['editor']])
        ->call('save')
        ->assertHasFormErrors(['kami_roles'])
        // Its colon would read as a rule's parameters in `assertHasFormErrors()`.
        ->assertSee(__('kami::admin.roles.lockout'));

    expect($ada->fresh()?->name)->toBe('Ada Lovelace')
        ->and($ada->fresh()?->kamiRoles())->toBe(['administrator'])
        ->and(collect(auditEvents())->pluck('event')->all())->toBe(['kami.users.lockout-refused']);
});

it('keeps a Role the User holds but config/kami.php no longer declares, listed by its handle', function () {
    $grace = userWith('Grace', 'editor', 'legacy');

    usersPage(EditUser::class, parameters: ['record' => $grace->getKey()])
        ->assertSee('legacy')
        ->fillForm(['name' => 'Grace Hopper'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($grace->fresh()?->kamiRoles())->toEqualCanonicalizing(['editor', 'legacy']);
});

it('deletes a User, their Roles with them', function () {
    $grace = userWith('Grace', 'editor');

    usersPage(ListUsers::class)
        ->callAction(TestAction::make('delete')->table($grace))
        ->assertNotified();

    expect(User::query()->whereKey($grace->getKey())->exists())->toBeFalse()
        ->and(DB::table('kami_role_user')->where('user_id', $grace->getKey())->exists())->toBeFalse()
        ->and(collect(auditEvents())->pluck('event')->all())->toBe(['kami.users.deleted']);
});

it('refuses deleting the last User holding kami.users.manage, saying why', function () {
    $ada = signInWith('administrator');
    Filament::setCurrentPanel('admin');

    Livewire::test(EditUser::class, ['record' => $ada->getKey()])
        ->callAction('delete')
        ->assertNotified(__('kami::admin.roles.lockout_delete'));

    expect($ada->fresh())->not->toBeNull()
        ->and(collect(auditEvents())->pluck('event')->all())->toBe(['kami.users.lockout-refused']);
});

it('hides the field from a User without kami.users.manage, on a screen the host opens to them, and keeps the Roles', function () {
    $grace = userWith('Grace', 'administrator');
    Gate::policy(User::class, OpenUserPolicy::class);

    usersPage(EditUser::class, ['kami.entries.*.view'], ['record' => $grace->getKey()])
        ->assertFormFieldHidden('kami_roles')
        ->fillForm(['name' => 'Grace Hopper'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($grace->fresh()?->kamiRoles())->toBe(['administrator']);
});

it('renders the Users screen in English and French with no raw translation key', function (string $locale) {
    config(['app.locale' => $locale]);
    $grace = userWith('Grace', 'editor');
    signInWith('administrator');

    foreach (['/admin/users', '/admin/users/create', "/admin/users/{$grace->getKey()}/edit"] as $url) {
        expect((string) $this->get($url)->assertOk()->getContent())->not->toContain('kami::', $url)->not->toContain('kami-roles.', $url);
    }
})->with(['en', 'fr']);

/** A host policy opening its Users screen to every User. */
class OpenUserPolicy
{
    public function viewAny(): bool
    {
        return true;
    }

    public function view(): bool
    {
        return true;
    }

    public function update(): bool
    {
        return true;
    }

    public function delete(): bool
    {
        return false;
    }
}
