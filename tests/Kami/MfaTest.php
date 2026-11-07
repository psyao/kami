<?php

declare(strict_types=1);

use Filament\Actions\Exceptions\ActionNotResolvableException;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\Pages\EditProfile;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

/** A User holding `$role`, with MFA set up when `$codes` is given: its recovery codes, in clear. */
function mfaUser(string $role, ?array $codes = null): User
{
    // Without model events: a fixture, whose own `kami.users.created` row no test reads.
    $user = User::withoutEvents(fn () => User::forceCreate(['name' => 'Grace', 'email' => uniqid() . '@example.test', 'password' => Hash::make('secret-password')]));
    DB::table('kami_role_user')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->getKey(), 'role' => $role]);

    if ($codes !== null) {
        withMfa($user, $codes);
    }

    // The Workbench User is no FilamentUser: Filament admits it locally only.
    config(['app.env' => 'local']);

    return $user;
}

/** Filament's sign-in page, its password step passed: the challenge form is showing. */
function passwordStep(User $user): Testable
{
    Filament::setCurrentPanel('admin');

    return Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'secret-password'])
        ->call('authenticate')
        ->assertHasNoFormErrors();
}

it('sends a manager without MFA to set it up before any admin page', function (string $page) {
    $this->actingAs(mfaUser('administrator'));

    $this->get($page)->assertRedirect('/admin/multi-factor-authentication/set-up');
})->with(['/admin', '/admin/articles', '/admin/profile', 'a Preview' => '/admin/kami/preview/unknown']);

it('serves the set-up page to that manager', function () {
    $this->actingAs(mfaUser('administrator'));

    $this->get('/admin/multi-factor-authentication/set-up')->assertOk()->assertSee(__('filament-panels::auth/multi-factor/app/actions/set-up.label'));
});

it('lets a manager with MFA in', function () {
    $this->actingAs(mfaUser('administrator', ['a-code']));

    $this->get('/admin')->assertOk();
});

it('lets an editor in without MFA, and offers it on the profile page', function () {
    $this->actingAs(mfaUser('editor'));

    $this->get('/admin')->assertOk();
    $this->get('/admin/profile')->assertOk()->assertSee(__('filament-panels::auth/multi-factor/app/actions/set-up.label'));
});

it('asks the code at every sign-in, after the password', function () {
    $user = mfaUser('editor', ['a-code']);

    $page = passwordStep($user);
    $this->assertGuest();

    $page->set('data.multiFactor.app.code', '000000')->call('authenticate')->assertHasErrors();
    $this->assertGuest();

    $page->set('data.multiFactor.app.code', (new Google2FA)->getCurrentOtp(MFA_SECRET))->call('authenticate')->assertHasNoErrors();
    $this->assertAuthenticatedAs($user);
});

it('signs in once with each recovery code, and records its use', function () {
    $user = mfaUser('administrator', ['first-code', 'second-code']);

    passwordStep($user)->set('data.multiFactor.app.useRecoveryCode', true)->set('data.multiFactor.app.recoveryCode', 'first-code')->call('authenticate')->assertHasNoErrors();
    $this->assertAuthenticatedAs($user);
    auth()->logout();

    passwordStep($user)->set('data.multiFactor.app.useRecoveryCode', true)->set('data.multiFactor.app.recoveryCode', 'first-code')->call('authenticate')->assertHasErrors();
    $this->assertGuest();

    expect($user->fresh()->getAppAuthenticationRecoveryCodes())->toHaveCount(1)
        ->and(array_column(auditEvents(), 'event'))->toBe(['kami.mfa.recovery-used'])
        ->and(auditEvents()[0])->toMatchArray(['subject_type' => 'user', 'subject_id' => (string) $user->getKey(), 'user_id' => (string) $user->getKey()]);
});

it('stores the authenticator encrypted in its own table, and records set-up and disabling', function () {
    $user = mfaUser('editor');
    $this->actingAs($user);
    $app = AppAuthentication::make()->recoverable();

    DB::transaction(function () use ($app, $user): void {
        $app->saveSecret($user, MFA_SECRET);
        $app->saveRecoveryCodes($user, ['a-code', 'b-code']);
    });
    // Regenerated codes are new hashes, not a code used.
    $app->saveRecoveryCodes($user, ['c-code']);

    $row = DB::table('kami_user_mfa')->sole();
    expect($row->secret)->not->toContain(MFA_SECRET)
        ->and($user->fresh()->getAppAuthenticationSecret())->toBe(MFA_SECRET)
        ->and(Hash::check('c-code', $user->fresh()->getAppAuthenticationRecoveryCodes()[0]))->toBeTrue()
        ->and(Schema::getColumnListing('users'))->not->toContain('app_authentication_secret');

    DB::transaction(function () use ($app, $user): void {
        $app->saveSecret($user, null);
        $app->saveRecoveryCodes($user, null);
    });

    expect(DB::table('kami_user_mfa')->count())->toBe(0)
        ->and(array_column(auditEvents(), 'event'))->toBe(['kami.mfa.enabled', 'kami.mfa.disabled']);
});

it('lets an editor disable MFA, never a manager', function (string $role, bool $allowed) {
    $this->actingAs(mfaUser($role, ['a-code']));
    Filament::setCurrentPanel('admin');
    $disable = TestAction::make('disableAppAuthentication')->schemaComponent(schema: 'content');

    $disabling = fn () => Livewire::test(EditProfile::class)
        ->callAction($disable, ['password' => 'secret-password', 'code' => (new Google2FA)->getCurrentOtp(MFA_SECRET)])
        ->assertHasNoActionErrors();

    // Hidden, so Filament refuses even a Livewire call made by hand.
    $allowed ? $disabling() : expect($disabling)->toThrow(ActionNotResolvableException::class);

    expect(DB::table('kami_user_mfa')->count())->toBe($allowed ? 0 : 1);
})->with([
    'editor' => ['editor', true],
    'manager' => ['administrator', false],
]);

it('deletes the authenticator with its User', function () {
    mfaUser('editor', ['a-code'])->delete();

    expect(DB::table('kami_user_mfa')->count())->toBe(0);
});
