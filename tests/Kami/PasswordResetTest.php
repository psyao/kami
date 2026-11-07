<?php

declare(strict_types=1);

use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Auth\User as PlainUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Psyao\Kami\Admin\KamiPlugin;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

/** The reset request page the admin panel serves, as Filament routes it. */
function resetRequestPage(): Testable
{
    Filament::setCurrentPanel('admin');

    return Livewire::test(Filament::getPanel('admin')->getRequestPasswordResetRouteAction());
}

function grace(): User
{
    return User::forceCreate(['name' => 'Grace', 'email' => 'grace@example.test', 'password' => Hash::make('secret-password')]);
}

it('answers a known, an unknown and a throttled address the same way', function (string $email, int $earlier) {
    grace();
    Notification::fake();
    $page = resetRequestPage();

    foreach (range(1, $earlier) as $attempt) {
        $page->fillForm(['email' => $email])->call('request');
        app(DeferredCallbackCollection::class)->invoke();
    }

    $page->fillForm(['email' => $email])->call('request')
        ->assertHasNoFormErrors()
        ->assertNotified(__('kami::admin.password_reset.sent'));
})->with([
    'known' => ['grace@example.test', 0],
    'unknown' => ['nobody@example.test', 0],
    // The broker refuses a second link within a minute: Password::RESET_THROTTLED.
    'throttled' => ['grace@example.test', 1],
]);

it('sends the link only after the response, and not through the queue, as no worker runs on shared hosting', function () {
    grace();
    // A queued email would never leave.
    Queue::fake();
    config(['mail.default' => 'array']);
    $sent = fn () => app('mailer')->getSymfonyTransport()->messages();

    resetRequestPage()->fillForm(['email' => 'grace@example.test'])->call('request');

    expect($sent())->toHaveCount(0);

    app(DeferredCallbackCollection::class)->invoke();

    expect($sent())->toHaveCount(1)
        ->and($sent()->first()->getEnvelope()->getRecipients()[0]->getAddress())->toBe('grace@example.test');
});

it('serves Kami\'s page whether the panel enables reset before or after the plugin, and keeps a host\'s own page', function () {
    $host = (new class extends RequestPasswordReset {})::class;
    $panels = [
        'before' => Panel::make()->id('before')->passwordReset()->plugin(KamiPlugin::make()),
        'after' => Panel::make()->id('after')->plugin(KamiPlugin::make())->passwordReset(),
        'host' => Panel::make()->id('host')->passwordReset($host)->plugin(KamiPlugin::make()),
    ];

    // What Filament's route file does for each panel, before it reads the reset page's class.
    foreach ($panels as $panel) {
        foreach ($panel->getRoutes() as $routes) {
            $routes($panel);
        }
    }

    expect($panels['before']->getRequestPasswordResetRouteAction())->not->toBe(RequestPasswordReset::class)->toBe(Filament::getPanel('admin')->getRequestPasswordResetRouteAction())
        ->and($panels['after']->getRequestPasswordResetRouteAction())->toBe($panels['before']->getRequestPasswordResetRouteAction())
        ->and($panels['host']->getRequestPasswordResetRouteAction())->toBe($host);
});

it('records a completed reset in the Audit log, with the User as subject and actor', function () {
    $grace = grace();
    $token = Password::broker()->createToken($grace);
    Filament::setCurrentPanel('admin');

    Livewire::test(ResetPassword::class, ['email' => 'grace@example.test', 'token' => $token])
        ->fillForm(['password' => 'new-secret-password', 'passwordConfirmation' => 'new-secret-password'])
        ->call('resetPassword')
        ->assertHasNoFormErrors();

    expect(DB::table('kami_audit_events')->get(['event', 'user_id', 'actor', 'subject_type', 'subject_id'])->map(fn (object $row) => (array) $row)->all())->toEqual([
        ['event' => 'kami.auth.password-reset', 'user_id' => $grace->getKey(), 'actor' => 'user', 'subject_type' => 'user', 'subject_id' => $grace->getKey()],
    ]);
});

it('records nothing for the reset of a model that is not a Kami User', function () {
    event(new PasswordReset(new PlainUser));

    expect(DB::table('kami_audit_events')->count())->toBe(0);
});
