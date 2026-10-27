<?php

namespace Psyao\Kami\Support\Internal\Doctor;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/** @internal */
#[Description('Check that this environment can run Kami')]
#[Signature('kami:doctor {--strict : Fail on warnings too}')]
final class DoctorCommand extends Command
{
    use PrintsProblems;

    public function handle(Doctor $doctor): int
    {
        $problems = $doctor->problems();

        $this->printProblems($problems);

        if ($problems === []) {
            $this->components->info('No problem found.');
        }

        // Read by the admin's "Needs attention" widget. An unwritable storage is already an error above: never create it, never throw.
        if (is_writable(storage_path())) {
            try {
                File::ensureDirectoryExists(storage_path('framework/kami'));
                File::put(storage_path('framework/kami/doctor.json'), json_encode([
                    'checked_at' => now()->toIso8601String(),
                    'problems' => $problems,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } catch (Throwable) {
                $this->components->warn('The result could not be written to ' . storage_path('framework/kami/doctor.json') . '.');
            }
        }

        $failing = array_filter($problems, fn (Problem $problem) => $problem->severity === Severity::Error || $this->option('strict'));

        return $failing === [] ? self::SUCCESS : self::FAILURE;
    }
}
