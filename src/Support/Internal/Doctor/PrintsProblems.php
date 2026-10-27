<?php

declare(strict_types=1);

namespace Psyao\Kami\Support\Internal\Doctor;

use Illuminate\Console\Command;

/**
 * Prints each Problem with its fix and its guide, the same way in `kami:doctor` and `kami:install`.
 *
 * @internal
 *
 * @phpstan-require-extends Command
 */
trait PrintsProblems
{
    /** @param list<Problem> $problems */
    private function printProblems(array $problems): void
    {
        foreach ($problems as $problem) {
            match ($problem->severity) {
                Severity::Error => $this->components->error($problem->message),
                Severity::Warning => $this->components->warn($problem->message),
            };
            $this->line("  Fix: {$problem->fix}");
            $this->line("  Guide: {$problem->guidePath()}");
            $this->newLine();
        }
    }
}
