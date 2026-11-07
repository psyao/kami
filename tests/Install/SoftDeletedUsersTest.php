<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Psyao\Kami\Users\Actions\AssignRoles;
use Workbench\App\Models\User;

/** A host's User model with `SoftDeletes`: its soft-deleted Users keep their `kami_role_user` rows, as the foreign key cascades on a hard delete only. */
class SoftDeletingUser extends User
{
    use SoftDeletes;

    protected $table = 'users';
}

beforeEach(function () {
    $this->bootWithKamiOnAPanel();
    // kami:install migrates by itself, so no RefreshDatabase; CI also runs this file on MySQL and PostgreSQL, whose database outlives a test.
    Artisan::call('db:wipe', ['--force' => true]);
    $this->artisan('migrate')->assertSuccessful();
    // DDL: it commits on MySQL, so this file sits in the Install suite, which wipes the database itself.
    Schema::table('users', fn (Blueprint $table) => $table->softDeletes());
    config(['auth.providers.users.model' => SoftDeletingUser::class]);
});

function softDeletingUser(string $name, string ...$roles): SoftDeletingUser
{
    $user = SoftDeletingUser::query()->forceCreate(['name' => $name, 'email' => strtolower($name) . '@example.test', 'password' => 'hash']);
    resolve(AssignRoles::class)->handle($user, array_values($roles), null);

    return $user;
}

it('keeps the last manager the host\'s model still finds from losing the manage Role or being deleted', function () {
    $gone = softDeletingUser('Ada', 'administrator');
    $last = softDeletingUser('Grace', 'administrator');
    // Allowed: Grace still manages Users.
    $gone->delete();

    expect(fn () => resolve(AssignRoles::class)->handle($last, [], $last))->toThrow(ValidationException::class)
        ->and(fn () => $last->delete())->toThrow(ValidationException::class)
        ->and($last->fresh()?->kamiRoles())->toBe(['administrator']);
});

it('lets the last manager change the Roles of a soft-deleted one', function () {
    $gone = softDeletingUser('Ada', 'administrator');
    $last = softDeletingUser('Grace', 'administrator');
    $gone->delete();

    resolve(AssignRoles::class)->handle($gone, [], $last);

    expect($gone->kamiRoles())->toBe([]);
});

it('makes kami:install create the first administrator when the only manager is soft-deleted', function () {
    // A query's delete fires no model event, so the guard does not see it: how a host soft-deletes the last manager.
    SoftDeletingUser::query()->whereKey(softDeletingUser('Ada', 'administrator')->getKey())->delete();

    $this->artisan('kami:install', ['--no-interaction' => true, '--email' => 'grace@example.test', '--name' => 'Grace'])->assertSuccessful();

    expect(Auth::attempt(['email' => 'grace@example.test', 'password' => 'correct horse battery']))->toBeTrue()
        ->and(SoftDeletingUser::query()->sole()->kamiRoles())->toBe(['administrator']);
});

it('leaves soft-deleted Users out of the doctor\'s counts', function () {
    // Filament opens its panel to a User that is not a `FilamentUser` in the local environment only.
    config(['app.env' => 'local']);
    withMfa(softDeletingUser('Ada', 'administrator'));
    $manager = softDeletingUser('Grace', 'administrator');
    $customer = softDeletingUser('Linus');

    $this->artisan('kami:doctor')
        ->expectsOutputToContain('1 User who may manage Users has no MFA set up')
        ->expectsOutputToContain('1 User with no Kami Role can sign in to the panel admin');

    $manager->delete();
    $customer->delete();

    $this->artisan('kami:doctor')
        ->doesntExpectOutputToContain('no MFA set up')
        ->doesntExpectOutputToContain('with no Kami Role');
});
