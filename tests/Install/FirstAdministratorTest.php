<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
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

function existingUser(): User
{
    test()->artisan('migrate')->assertSuccessful();

    return User::query()->forceCreate(['name' => 'Grace', 'email' => 'grace@example.test', 'password' => 'hash']);
}

const GRANT_QUESTION = 'grace@example.test (Grace) already exists. Give them the administrator Role?';

it('grants an existing User the administrator Role without a terminal, changing nothing else', function () {
    $existing = existingUser();
    DB::table('kami_role_user')->insert(['id' => (string) Str::ulid(), 'user_id' => $existing->getKey(), 'role' => 'editor']);
    unset($_SERVER['KAMI_ADMIN_PASSWORD']);

    $this->artisan('kami:install', ['--no-interaction' => true, '--email' => 'grace@example.test'])
        ->expectsOutputToContain('grace@example.test, an existing User, is now an administrator.')
        ->doesntExpectOutputToContain('--name')
        ->assertSuccessful();

    expect($existing->fresh()->only('name', 'password'))->toBe(['name' => 'Grace', 'password' => 'hash'])
        ->and(User::query()->count())->toBe(1)
        ->and(DB::table('kami_role_user')->orderBy('role')->pluck('role')->all())->toBe(['administrator', 'editor'])
        ->and(Gate::forUser($existing->fresh())->allows('kami.users.manage'))->toBeTrue();
});

it('ignores --name and KAMI_ADMIN_PASSWORD for an existing User, saying so for --name', function () {
    $existing = existingUser();

    $this->artisan('kami:install', ['--no-interaction' => true, '--email' => 'grace@example.test', '--name' => 'Ada'])
        ->expectsOutputToContain('--name was ignored')
        ->assertSuccessful();

    expect($existing->fresh()->only('name', 'password'))->toBe(['name' => 'Grace', 'password' => 'hash']);
});

it('asks before granting in a terminal, whether the email came from --email or the prompt', function (array $options) {
    $existing = existingUser();

    $command = $this->artisan('kami:install', $options);

    if ($options === []) {
        $command->expectsQuestion('Email of the first administrator', 'grace@example.test');
    }

    $command->expectsConfirmation(GRANT_QUESTION, 'yes')->assertSuccessful()->run();

    expect(Gate::forUser($existing->fresh())->allows('kami.users.manage'))->toBeTrue()
        ->and($existing->fresh()->password)->toBe('hash');
})->with([
    '--email' => [['--email' => 'grace@example.test']],
    'prompt' => [[]],
]);

it('fails and changes nothing when --email is refused at the confirmation', function () {
    $existing = existingUser();

    $this->artisan('kami:install', ['--email' => 'grace@example.test'])
        ->expectsConfirmation(GRANT_QUESTION, 'no')
        ->expectsOutputToContain('grace@example.test did not get the administrator Role.')
        ->assertFailed();

    expect(DB::table('kami_role_user')->count())->toBe(0)
        ->and(User::query()->count())->toBe(1)
        ->and($existing->fresh()->only('name', 'password'))->toBe(['name' => 'Grace', 'password' => 'hash']);
});

it('returns to the email prompt when the prompted email is refused at the confirmation', function () {
    $existing = existingUser();

    $this->artisan('kami:install')
        ->expectsQuestion('Email of the first administrator', 'grace@example.test')
        ->expectsConfirmation(GRANT_QUESTION, 'no')
        ->expectsQuestion('Email of the first administrator', 'ada@example.test')
        ->expectsQuestion('Name of the first administrator', 'Ada')
        ->expectsQuestion('Password of the first administrator', 'correct horse battery')
        ->assertSuccessful();

    expect(Gate::forUser($existing->fresh())->allows('kami.users.manage'))->toBeFalse()
        ->and(Gate::forUser(User::query()->where('email', 'ada@example.test')->sole())->allows('kami.users.manage'))->toBeTrue();
});

it('treats a bare Enter at the confirmation as no', function () {
    existingUser();
    // Real question helper reading a real stream: expectsConfirmation() supplies its own answer and would hide the default.
    $input = new ArrayInput(['command' => 'kami:install', '--email' => 'grace@example.test']);
    $input->setStream(fopen('data://text/plain,' . "\n", 'r'));

    expect(app(Kernel::class)->handle($input, new BufferedOutput))->toBe(1)
        ->and(DB::table('kami_role_user')->count())->toBe(0);
});

it('does nothing with --email once a User may manage Users, whatever the email', function (string $email) {
    $this->artisan('kami:install', ['--no-interaction' => true, '--email' => 'ada@example.test', '--name' => 'Ada'])->assertSuccessful();
    User::query()->forceCreate(['name' => 'Grace', 'email' => 'grace@example.test', 'password' => 'hash']);

    $this->artisan('kami:install', ['--no-interaction' => true, '--email' => $email, '--name' => 'Other'])->assertSuccessful();

    expect(User::query()->count())->toBe(2)
        ->and(DB::table('kami_role_user')->count())->toBe(1);
})->with(['existing' => 'grace@example.test', 'unknown' => 'new@example.test']);

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
