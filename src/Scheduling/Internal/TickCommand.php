<?php

declare(strict_types=1);

namespace Psyao\Kami\Scheduling\Internal;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The CLI Tick, from a server's crontab every minute (ADR 0020).
 *
 * @internal
 */
#[Description('Run every overdue Scheduled task, in-process')]
#[Signature('kami:tick')]
final class TickCommand extends Command
{
    /** Ends before cron's next minute. */
    private const int BUDGET = 50;

    public function handle(): int
    {
        $result = Tick::run('cli', self::BUDGET);
        $this->line($result);

        return $result === 'FAILED' ? self::FAILURE : self::SUCCESS;
    }
}
