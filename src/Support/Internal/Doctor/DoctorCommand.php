<?php

namespace Psyao\Kami\Support\Internal\Doctor;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/** @internal */
#[Description('Check that this environment can run Kami')]
#[Signature('kami:doctor {--strict : Fail on warnings too}')]
final class DoctorCommand extends Command
{
    public function handle(Doctor $doctor): int
    {
        $problems = $doctor->problems();

        foreach ($problems as $problem) {
            match ($problem->severity) {
                Severity::Error => $this->components->error($problem->message),
                Severity::Warning => $this->components->warn($problem->message),
            };
            $this->line("  Fix: {$problem->fix}");
            $this->line('  Guide: ' . $this->guide($problem->guide));
            $this->newLine();
        }

        if ($problems === []) {
            $this->components->info('No problem found.');
        }

        // Read by the admin's "Needs attention" widget. Skipped when storage is unwritable: that is already an error above.
        if (is_writable(storage_path())) {
            File::ensureDirectoryExists(storage_path('framework/kami'));
            File::put(storage_path('framework/kami/doctor.json'), json_encode([
                'checked_at' => now()->toIso8601String(),
                'problems' => $problems,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }

        $failing = array_filter($problems, fn (Problem $problem) => $problem->severity === Severity::Error || $this->option('strict'));

        return $failing === [] ? self::SUCCESS : self::FAILURE;
    }

    /** The guide shipped with the installed version, relative to the site when Kami sits in its `vendor/`. */
    private function guide(string $page): string
    {
        return Str::after(dirname(__DIR__, 4) . "/docs/guide/{$page}", base_path() . DIRECTORY_SEPARATOR);
    }
}
