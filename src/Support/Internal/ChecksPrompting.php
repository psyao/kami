<?php

declare(strict_types=1);

namespace Psyao\Kami\Support\Internal;

use Illuminate\Console\Command;

/**
 * Whether a prompt can be answered: Symfony's isInteractive() alone stays true for a process with no terminal, while Prompts checks the terminal itself.
 * Unlike Laravel's check for Prompts, --no-interaction wins under test too, and Windows without a terminal is refused rather than falling back to Symfony's questions.
 *
 * @internal
 *
 * @phpstan-require-extends Command
 */
trait ChecksPrompting
{
    private function canPrompt(): bool
    {
        return $this->input->isInteractive() && ((defined('STDIN') && stream_isatty(STDIN)) || $this->laravel->runningUnitTests());
    }
}
