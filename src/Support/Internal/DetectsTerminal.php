<?php

declare(strict_types=1);

namespace Psyao\Kami\Support\Internal;

use Illuminate\Console\Command;

/**
 * Whether a prompt can be answered, as Laravel decides for Prompts: Symfony's isInteractive() alone stays true for a process with no terminal.
 *
 * @internal
 *
 * @phpstan-require-extends Command
 */
trait DetectsTerminal
{
    private function hasTerminal(): bool
    {
        return $this->input->isInteractive() && ((defined('STDIN') && stream_isatty(STDIN)) || $this->laravel->runningUnitTests());
    }
}
