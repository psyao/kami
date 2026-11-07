<?php

declare(strict_types=1);

use Filament\Actions\Exceptions\ActionNotResolvableException;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\Pages\EditProfile;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
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
        // The second sign-in, refused, records nothing of its own: a wrong code is not a failed password.
        ->and(array_column(auditEvents(), 'event'))->toBe(['kami.mfa.recovery-used', 'kami.auth.signed-in'])
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

describe('kami:user:reset-mfa', function (): void {
    it('clears the authenticator of the User stored under any spelling of the email, so it sets MFA up again', function () {
        $user = mfaUser('administrator', ['a-code']);
        $user->forceFill(['email' => 'Grace@Example.test'])->saveQuietly();

        $this->artisan('kami:user:reset-mfa', ['email' => 'grace@example.TEST'])
            ->expectsOutputToContain('Grace@Example.test sets MFA up again at the next sign-in.')
            ->assertSuccessful();

        expect(DB::table('kami_user_mfa')->count())->toBe(0)
            ->and(auditEvents())->toHaveCount(1)
            ->and(auditEvents()[0])->toMatchArray(['event' => 'kami.mfa.reset', 'actor' => 'cli', 'user_id' => null, 'subject_type' => 'user', 'subject_id' => (string) $user->getKey()]);
    });

    it('changes nothing for a User with no MFA', function () {
        $user = mfaUser('editor');

        $this->artisan('kami:user:reset-mfa', ['email' => $user->email])
            ->expectsOutputToContain('has no MFA set up in kami_user_mfa.')
            ->assertSuccessful();

        expect(auditEvents())->toBe([]);
    });

    // Several stored spellings: tests/Install, whose database may drop the unique index MySQL's collation enforces.
    it('refuses, changing nothing, an email no User has', function (string $email, string $message) {
        mfaUser('editor', ['a-code']);

        $this->artisan('kami:user:reset-mfa', ['email' => $email])
            ->expectsOutputToContain($message)
            ->assertFailed();

        expect(DB::table('kami_user_mfa')->count())->toBe(1)
            ->and(auditEvents())->toBe([]);
    })->with([
        'unknown email' => ['nobody@example.test', 'No User has the email nobody@example.test.'],
        'not an email' => ['nobody', 'must be a valid email address'],
    ]);
});

it('counts the Users who may manage Users and have no MFA yet, failing kami:doctor with --strict only', function () {
    withConversionsServed();
    mfaUser('administrator');
    mfaUser('administrator');
    mfaUser('administrator', ['a-code']);
    mfaUser('editor');

    $this->artisan('kami:doctor')
        ->expectsOutputToContain('2 Users who may manage Users have no MFA set up: the panel admin sends them to set it up at the next sign-in.')
        ->expectsOutputToContain('docs/guide/users-and-roles.md#mfa')
        ->assertSuccessful();

    $this->artisan('kami:doctor', ['--strict' => true])->assertFailed();
});

it('counts a manager whose authenticator the app key no longer reads, as one without MFA, instead of failing', function () {
    withConversionsServed();
    mfaUser('administrator', ['a-code']);
    // APP_KEY rotated without APP_PREVIOUS_KEYS: kami:user:reset-mfa is the rescue, so the doctor must still run.
    config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');

    $this->artisan('kami:doctor')
        ->expectsOutputToContain('1 User who may manage Users has no MFA set up')
        ->assertSuccessful();
});

it('says nothing once every User who may manage Users has MFA set up', function () {
    withConversionsServed();
    mfaUser('administrator', ['a-code']);
    mfaUser('editor');

    $this->artisan('kami:doctor')->doesntExpectOutputToContain('no MFA set up')->assertSuccessful();
});
