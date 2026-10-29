<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Workbench\App\Models\User;

beforeEach(function () {
    $this->bootWithKamiOnAPanel();
    $_SERVER['KAMI_ADMIN_PASSWORD'] = 'correct horse battery';
});

afterEach(function () {
    unset($_SERVER['KAMI_ADMIN_PASSWORD']);
});

it('creates the first administrator from options and KAMI_ADMIN_PASSWORD without a terminal', function () {
    $this->artisan('kami:install', ['--no-interaction' => true, '--email' => 'ada@example.test', '--name' => 'Ada'])
        ->assertSuccessful();

    expect(Auth::attempt(['email' => 'ada@example.test', 'password' => 'correct horse battery']))->toBeTrue()
        ->and(Gate::forUser(User::query()->sole())->allows('kami.users.manage'))->toBeTrue()
        ->and(User::query()->sole()->name)->toBe('Ada');
});

it('prompts for the email, the name and the password in a terminal', function () {
    unset($_SERVER['KAMI_ADMIN_PASSWORD']);

    $this->artisan('kami:install')
        ->expectsQuestion('Email of the first administrator', 'ada@example.test')
        ->expectsQuestion('Name of the first administrator', 'Ada')
        ->expectsQuestion('Password of the first administrator', 'correct horse battery')
        ->doesntExpectOutputToContain('correct horse battery')
        ->assertSuccessful();

    expect(Auth::attempt(['email' => 'ada@example.test', 'password' => 'correct horse battery']))->toBeTrue()
        ->and(Gate::forUser(User::query()->sole())->allows('kami.users.manage'))->toBeTrue();
});

it('fails without a terminal, naming the missing input', function (array $options, string $missing) {
    if ($missing === 'KAMI_ADMIN_PASSWORD') {
        unset($_SERVER['KAMI_ADMIN_PASSWORD']);
    }

    $this->artisan('kami:install', ['--no-interaction' => true, ...$options])
        ->expectsOutputToContain("Pass {$missing}")
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
})->with([
    'email' => [['--name' => 'Ada'], '--email'],
    'name' => [['--email' => 'ada@example.test'], '--name'],
    'password' => [['--email' => 'ada@example.test', '--name' => 'Ada'], 'KAMI_ADMIN_PASSWORD'],
]);

it('refuses an email that names an existing User, leaving that User as it was', function () {
    $this->artisan('migrate')->assertSuccessful();
    $existing = User::query()->forceCreate(['name' => 'Grace', 'email' => 'grace@example.test', 'password' => 'hash']);

    $this->artisan('kami:install', ['--no-interaction' => true, '--email' => 'grace@example.test', '--name' => 'Ada'])
        ->expectsOutputToContain('A User with the email grace@example.test already exists')
        ->assertFailed();

    expect($existing->fresh()->only('name', 'password'))->toBe(['name' => 'Grace', 'password' => 'hash'])
        ->and(Gate::forUser($existing->fresh())->allows('kami.users.manage'))->toBeFalse();
});

it('changes nothing when an administrator exists', function () {
    $this->artisan('kami:install', ['--no-interaction' => true, '--email' => 'ada@example.test', '--name' => 'Ada'])->assertSuccessful();
    unset($_SERVER['KAMI_ADMIN_PASSWORD']);

    $this->artisan('kami:install', ['--no-interaction' => true])->assertSuccessful();

    expect(User::query()->count())->toBe(1);
});
