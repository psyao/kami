<?php

namespace Psyao\Kami\Support\Internal\Install;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Psyao\Kami\Support\Internal\Doctor\Doctor;
use Psyao\Kami\Support\Internal\Doctor\PrintsProblems;

/** @internal */
#[Description('Install Kami, or bring it up to date after a deploy')]
#[Signature('kami:install')]
final class InstallCommand extends Command
{
    use PrintsProblems;

    public function handle(Doctor $doctor, PluginRegistration $plugin): int
    {
        // A broken environment fails the deploy before anything is migrated; warnings are kami:doctor's to report.
        if (($problems = $doctor->blocking()) !== []) {
            $this->printProblems($problems);

            return self::FAILURE;
        }

        // Kami never edits the host's files (ADR 0001): it says what to add and stops before changing anything.
        if (! $plugin->onAPanel) {
            $this->components->error('No Filament panel carries Kami yet. Add these lines to your panel provider, then run kami:install again.');
            $this->line('    use Psyao\Kami\Admin\KamiPlugin;');
            $this->newLine();
            $this->line('    ->plugin(KamiPlugin::make())');
            $this->newLine();
            $this->line('  An application without a panel creates one first with php artisan make:filament-panel admin.');

            return self::FAILURE;
        }

        // Each step fails the deploy on its own failure: a later step never runs on a half-done one.
        if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        // The admin's assets are copied to public/, so the server needs no Node (ADR 0001); the command name keeps the core free of Filament (ADR 0011).
        if ($this->call('filament:assets') !== self::SUCCESS) {
            return self::FAILURE;
        }

        // ponytail: Kami keeps no cache in M1; clearing them (the Content version bump, ADR 0021) lands here in M2.

        $this->components->info('Kami is installed.');

        return self::SUCCESS;
    }
}
