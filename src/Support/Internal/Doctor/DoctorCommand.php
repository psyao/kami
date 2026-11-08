<?php

declare(strict_types=1);

namespace Psyao\Kami\Support\Internal\Doctor;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Psyao\Kami\Media\Internal\ImageFormats;
use Psyao\Kami\Routing\Internal\BenchCommand;
use Throwable;

/** @internal */
#[Description('Check that this environment can run Kami')]
#[Signature('kami:doctor {--strict : Fail on warnings too}')]
final class DoctorCommand extends Command
{
    use PrintsProblems;

    public function handle(Doctor $doctor, ImageFormats $formats): int
    {
        $this->printFormats($formats);
        $this->printBench();
        $problems = $doctor->problems();

        $this->printProblems($problems);

        if ($problems === []) {
            $this->components->info('No problem found.');
        }

        // Read by the admin's "Needs attention" widget. An unwritable storage is already an error above: never create it, never throw.
        if (is_writable(storage_path())) {
            try {
                File::ensureDirectoryExists(storage_path('framework/kami'));
                // The report describes this machine: keep it out of git, as Laravel does cache/, sessions/ and views/.
                File::put(storage_path('framework/kami/.gitignore'), "*\n!.gitignore\n");
                // Replaced whole, so the widget never reads half a file.
                File::replace(self::resultPath(), json_encode([
                    'checked_at' => now()->toIso8601String(),
                    'problems' => $problems,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } catch (Throwable) {
                $this->components->warn('The result could not be written to ' . self::resultPath() . '.');
            }
        }

        $failing = array_filter($problems, fn (Problem $problem): bool => $problem->severity === Severity::Error || ($problem->severity === Severity::Warning && $this->option('strict')));

        return $failing === [] ? self::SUCCESS : self::FAILURE;
    }

    /** What visitors are offered, not a Problem: a host without AVIF or WebP still serves every image (ADR 0017). */
    private function printFormats(ImageFormats $formats): void
    {
        // The command line may load other extensions than the web: tested afresh, never read from the web's cache.
        $tested = $formats->probe();
        $this->components->twoColumnDetail('<options=bold>Image formats</>', 'on the command line');

        foreach (ImageFormats::FORMATS as $format => $name) {
            $this->components->twoColumnDetail($name, match (true) {
                $format === 'avif' && ! Config::boolean('kami.media.avif') => 'off (kami.media.avif)',
                isset($tested[$format]) => $tested[$format],
                default => 'not available',
            });
        }

        $this->components->twoColumnDetail('JPEG, PNG', Config::string('images.default', 'gd') . ', the fallback');
        $this->newLine();
    }

    /** The last `kami:bench`, not a Problem: it measures the web, which the doctor cannot. */
    private function printBench(): void
    {
        $bench = BenchCommand::lastResult();

        if ($bench === null) {
            $this->components->twoColumnDetail('<options=bold>Page speed</>', 'never measured, run kami:bench');
            $this->newLine();

            return;
        }

        $this->components->twoColumnDetail('<options=bold>Page speed</>', sprintf('%s, %s', $bench['url'], $bench['measured_at']->utc()->format('Y-m-d H:i \U\T\C')));
        $this->components->twoColumnDetail('Cached', sprintf('%.1f ms', $bench['cached_ms']));
        $this->components->twoColumnDetail('Uncached', sprintf('%.1f ms', $bench['uncached_ms']));
        $this->newLine();
    }

    /** Where the last result lies, for the admin's "Needs attention" widget. */
    public static function resultPath(): string
    {
        return storage_path('framework/kami/doctor.json');
    }
}
