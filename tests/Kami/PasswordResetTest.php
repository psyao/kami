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
use Psyao\Kami\Admin\SetPasswordLink;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

/** The reset request page the admin panel serves, as Filament routes it. */
function resetRequestPage(): Testable
{
    Filament::setCurrentPanel('admin');

    return Livewire::test(Filament::getPanel('admin')->getRequestPasswordResetRouteAction());
}

function resetUser(): User
{
    // Without model events: the User's own `kami.users.created` row is not what this file reads.
    return User::withoutEvents(fn () => User::forceCreate(['name' => 'Grace', 'email' => 'grace@example.test', 'password' => Hash::make('secret-password')]));
}

it('answers a known, an unknown and a throttled address the same way', function (string $email, int $earlier, int $links) {
    resetUser();
    Notification::fake();
    $page = resetRequestPage();

    for ($attempt = 0; $attempt < $earlier; $attempt++) {
        // Asserting pulls the earlier notification, so the last request's stands alone below.
        $page->fillForm(['email' => $email])->call('request')->assertNotified(__('kami::admin.password_reset.sent'));
        app(DeferredCallbackCollection::class)->invoke();
    }

    $page->fillForm(['email' => $email])->call('request')
        ->assertHasNoFormErrors()
        ->assertNotified(__('kami::admin.password_reset.sent'));
    app(DeferredCallbackCollection::class)->invoke();

    Notification::assertCount($links);
})->with([
    'known' => ['grace@example.test', 0, 1],
    'unknown' => ['nobody@example.test', 0, 0],
    // The broker refuses a second link within a minute: Password::RESET_THROTTLED, so one link in all.
    'throttled' => ['grace@example.test', 1, 1],
]);

it('sends the link only after the response, and not through the queue, as no worker runs on shared hosting', function () {
    resetUser();
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

it('serves Kami\'s pages whether the panel enables reset before or after the plugin, and keeps a host\'s own page', function () {
    $host = (new class extends RequestPasswordReset {})::class;
    $hostReset = (new class extends ResetPassword {})::class;
    $panels = [
        'before' => Panel::make()->id('before')->passwordReset()->plugin(KamiPlugin::make()),
        'after' => Panel::make()->id('after')->plugin(KamiPlugin::make())->passwordReset(),
        'host' => Panel::make()->id('host')->passwordReset($host)->plugin(KamiPlugin::make()),
        'host reset' => Panel::make()->id('host-reset')->passwordReset(resetAction: $hostReset)->plugin(KamiPlugin::make()),
    ];

    // What Filament's route file does for each panel, before it reads the reset page's class.
    foreach ($panels as $panel) {
        foreach ($panel->getRoutes() as $routes) {
            $routes($panel);
        }
    }

    expect($panels['before']->getRequestPasswordResetRouteAction())->not->toBe(RequestPasswordReset::class)->toBe(Filament::getPanel('admin')->getRequestPasswordResetRouteAction())
        ->and($panels['after']->getRequestPasswordResetRouteAction())->toBe($panels['before']->getRequestPasswordResetRouteAction())
        ->and($panels['host']->getRequestPasswordResetRouteAction())->toBe($host)
        ->and($panels['before']->getResetPasswordRouteAction())->not->toBe(ResetPassword::class)->toBe(Filament::getPanel('admin')->getResetPasswordRouteAction())
        ->and($panels['after']->getResetPasswordRouteAction())->toBe($panels['before']->getResetPasswordRouteAction())
        ->and($panels['host']->getResetPasswordRouteAction())->toBe($panels['before']->getResetPasswordRouteAction())
        ->and($panels['host reset']->getResetPasswordRouteAction())->toBe($hostReset)
        ->and($panels['host reset']->getRequestPasswordResetRouteAction())->toBe($panels['before']->getRequestPasswordResetRouteAction());
});

it('records a completed reset in the Audit log, with the User as subject and actor', function () {
    $grace = resetUser();
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

describe('the one-time set-password link', function (): void {
    it('refuses a panel without password reset, naming what it needs', function () {
        expect(fn () => SetPasswordLink::make(resetUser(), Panel::make()->id('bare')))
            ->toThrow(LogicException::class, '->passwordReset()');
    });

    it('opens a page that sets a password, where a reset link resets one', function (string $locale, string $title, string $button) {
        app()->setLocale($locale);
        Filament::setCurrentPanel('admin');
        $grace = resetUser();

        $this->get(SetPasswordLink::make($grace)['url'])->assertOk()->assertSee($title)->assertSee($button);
        $this->get(Filament::getPanel('admin')->getResetPasswordUrl(Password::broker()->createToken($grace), $grace))->assertOk()
            ->assertSee(__('filament-panels::auth/pages/password-reset/reset-password.heading'))->assertDontSee($title);
    })->with([['en', 'Set your password', 'Set password'], ['fr', 'Définissez votre mot de passe', 'Définir le mot de passe']]);

    it('says the password is set once chosen, where a reset says it is reset', function (bool $sets, string $title) {
        Filament::setCurrentPanel('admin');
        $grace = resetUser();
        $token = Password::broker()->createToken($grace);

        Livewire::withQueryParams($sets ? ['set' => '1'] : [])->test(Filament::getPanel('admin')->getResetPasswordRouteAction(), ['email' => 'grace@example.test', 'token' => $token])
            ->fillForm(['password' => 'new-secret-password', 'passwordConfirmation' => 'new-secret-password'])
            ->call('resetPassword')
            ->assertHasNoFormErrors()
            ->assertNotified($title);
    })->with([
        'set' => [true, 'Your password is set.'],
        'reset' => [false, 'Your password has been reset.'],
    ]);

    it('keeps its wording under the link\'s signature', function () {
        Filament::setCurrentPanel('admin');
        $url = SetPasswordLink::make(resetUser())['url'];

        $this->get(str_replace('set=1', 'set=0', $url))->assertForbidden();
    });
});
