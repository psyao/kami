<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Process;
use Workbench\App\Models\User;

beforeEach(fn () => $this->bootWithKamiOnAPanel());

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
        ->expectsOutputToContain('A User with this email already exists: use the email of a new User.')
        ->assertFailed();

    expect($existing->fresh()->only('name', 'password'))->toBe(['name' => 'Grace', 'password' => 'hash'])
        ->and(Gate::forUser($existing->fresh())->allows('kami.users.manage'))->toBeFalse();
});

it('changes nothing when an administrator exists', function () {
    $this->artisan('kami:install', ['--no-interaction' => true, '--email' => 'ada@example.test', '--name' => 'Ada'])->assertSuccessful();
    unset($_SERVER['KAMI_ADMIN_PASSWORD']);

    $this->artisan('kami:install', ['--no-interaction' => true])->assertSuccessful();

    expect(User::query()->count())->toBe(1)
        ->and(DB::table('kami_role_user')->pluck('role')->all())->toBe(['administrator']);
});

it('refuses while the administrator Role does not grant kami.users.manage', function () {
    config(['kami.roles' => ['administrator' => ['kami.entries.*.*']]]);

    $this->artisan('kami:install', ['--no-interaction' => true, '--email' => 'ada@example.test', '--name' => 'Ada'])
        ->expectsOutputToContain('The administrator Role in config/kami.php does not grant kami.users.manage')
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});

it('fails the same way from a process with no terminal, even without --no-interaction', function () {
    // A real process outside the testing environment, where Laravel treats every command as having a terminal.
    $result = Process::path(dirname(__DIR__, 2))
        ->env(['APP_ENV' => 'local', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'KAMI_ADMIN_PASSWORD' => false])
        ->run(['php', 'vendor/bin/testbench', 'kami:install']);

    expect($result->exitCode())->toBe(1)
        ->and($result->output() . $result->errorOutput())->toContain('Pass --email, --name, KAMI_ADMIN_PASSWORD')
        ->not->toContain('NonInteractiveValidationException');
});
