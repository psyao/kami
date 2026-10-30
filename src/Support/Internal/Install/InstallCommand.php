<?php

declare(strict_types=1);

namespace Psyao\Kami\Support\Internal\Install;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Psyao\Kami\Routing\Internal\SyncPaths;
use Psyao\Kami\Support\Internal\ChecksPrompting;
use Psyao\Kami\Support\Internal\Doctor\Doctor;
use Psyao\Kami\Support\Internal\Doctor\PrintsProblems;
use Psyao\Kami\Users\Internal\Permissions;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/** @internal */
#[Description('Install Kami, or bring it up to date after a deploy')]
#[Signature('kami:install
    {--email= : The first administrator\'s email, used while no User may manage Users}
    {--name= : The first administrator\'s name}')]
final class InstallCommand extends Command
{
    use ChecksPrompting;
    use PrintsProblems;

    public function handle(Doctor $doctor, PluginRegistration $registration, SyncPaths $syncPaths): int
    {
        // A broken environment fails the deploy before anything is migrated; warnings are kami:doctor's to report.
        if (($problems = $doctor->blocking()) !== []) {
            $this->printProblems($problems);

            return self::FAILURE;
        }

        // Kami never edits the host's files (ADR 0001): it says what to add and stops before changing anything.
        if (! $registration->onAPanel) {
            $this->components->error('No Filament panel carries Kami yet. Add these lines to your panel provider, then run kami:install again.');
            $this->line('    use Psyao\Kami\Admin\KamiPlugin;');
            $this->newLine();
            $this->line('    ->plugin(KamiPlugin::make())');
            $this->newLine();
            $this->line('  An application without a panel creates one first with php artisan make:filament-panel admin.');

            return self::FAILURE;
        }

        if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        // Filament's own command, present wherever Kami runs (ADR 0001): it copies the admin's assets to public/, so the server needs no Node.
        if ($this->call('filament:assets') !== self::SUCCESS) {
            return self::FAILURE;
        }

        if (! $this->syncPaths($syncPaths)) {
            return self::FAILURE;
        }

        if (! $this->firstAdministrator()) {
            return self::FAILURE;
        }

        // ponytail: Kami keeps no cache in M1; clearing them (the Content version bump, ADR 0021) lands here in M7.

        $this->components->info('Kami is installed.');

        return self::SUCCESS;
    }

    /** Moves URLs to follow the declarations (ADR 0008); an address another Entry holds fails the deploy, naming both sides. */
    private function syncPaths(SyncPaths $syncPaths): bool
    {
        $result = $syncPaths();
        $this->components->info("Paths: {$result['created']} created, {$result['rewritten']} rewritten, {$result['deleted']} deleted.");

        foreach ($result['clashes'] as $clash) {
            $this->components->error("Entry {$clash['entry_id']} cannot take /{$clash['path']} in {$clash['locale']}: Entry {$clash['holder']} holds it. Change one of the two slugs, then run kami:install again.");
        }

        return $result['clashes'] === [];
    }

    /**
     * Makes sure a User may manage Users (ADR 0001, step 7): while none does, an existing email gets the `administrator` Role and an unknown one creates the User.
     * The password is never an argument: it would stay in the shell history and the process list.
     */
    private function firstAdministrator(): bool
    {
        $granting = Permissions::rolesGranting('kami.users.manage');

        if (DB::table('kami_role_user')->whereIn('role', $granting)->exists()) {
            return true;
        }

        // Created without it, the administrator would not count, and every deploy would ask for another one.
        if (! in_array('administrator', $granting, true)) {
            $this->components->error('The administrator Role in config/kami.php does not grant kami.users.manage, so kami:install cannot create the first administrator. Give it kami.users.manage, or kami.*.');

            return false;
        }

        // kami_role_user points at this model (its migration), whichever guard the panel signs in with.
        /** @var class-string<Model> $users */
        $users = config('auth.providers.users.model');
        $rules = [
            'email' => ['bail', 'required', 'string', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', Password::defaults()],
        ];

        $interactive = $this->canPrompt();
        $emailOption = $this->option('email');

        while (true) {
            $email = is_string($emailOption) ? $emailOption : ($interactive ? text('Email of the first administrator', required: true, validate: ['email' => $rules['email']]) : null);
            // @phpstan-ignore argument.type (the base Model declares no email; the host's model does, as creating the User below assumes)
            $existing = $email === null ? null : $users::query()->where('email', $email)->first();

            if ($existing === null) {
                break;
            }

            // No password is checked here: on an application with open registration, a mistyped email would hand every right to a stranger. Without a terminal, --email is the consent.
            $existingName = $existing->getAttribute('name');

            if (! $interactive || confirm((is_string($existingName) && $existingName !== '' ? "{$email} ({$existingName})" : $email) . ' already exists. Give them the administrator Role?', default: false)) {
                DB::table('kami_role_user')->insert(['id' => (string) Str::ulid(), 'user_id' => $existing->getKey(), 'role' => 'administrator']);

                if (is_string($this->option('name'))) {
                    $this->components->warn('--name was ignored: an existing User keeps their name.');
                }

                $this->components->info("{$email}, an existing User, is now an administrator.");

                return true;
            }

            if (is_string($emailOption)) {
                $this->components->error("{$email} did not get the administrator Role. Pass the email of the User to make an administrator.");

                return false;
            }
        }

        $name = $this->option('name');
        $name = is_string($name) ? $name : ($interactive ? text('Name of the first administrator', required: true, validate: ['name' => $rules['name']]) : null);
        $password = $interactive ? password('Password of the first administrator', required: true, validate: ['password' => $rules['password']]) : Env::get('KAMI_ADMIN_PASSWORD');

        // ponytail: missing input fails; ADR 0001's one-time password link replaces the KAMI_ADMIN_PASSWORD requirement in M6.
        if ($email === null || $name === null || ! is_string($password)) {
            $missing = array_keys(array_filter(['--email' => $email === null, '--name' => $name === null, 'KAMI_ADMIN_PASSWORD' => ! is_string($password)]));
            $this->components->error('No User may manage Users yet, and kami:install cannot create the first administrator without a terminal. Pass ' . implode(', ', $missing) . ' (KAMI_ADMIN_PASSWORD in the environment, never as an argument).');

            return false;
        }

        $validator = Validator::make(['email' => $email, 'name' => $name, 'password' => $password], $rules);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return false;
        }

        DB::transaction(function () use ($users, $email, $name, $password): void {
            $user = new $users;
            $user->setAttribute('email', $email);
            $user->setAttribute('name', $name);
            $user->setAttribute('password', Hash::make($password));
            $user->save();
            DB::table('kami_role_user')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->getKey(), 'role' => 'administrator']);
        });

        $this->components->info("{$email} is the first administrator.");

        return true;
    }
}
