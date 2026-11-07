<?php

declare(strict_types=1);

namespace Psyao\Kami\Audit\Internal;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * Deletes the Audit log's events older than `kami.audit.retention` months, so it keeps no personal data forever (ADR 0022).
 * In chunks, each its own statement, within shared hosting's time and memory limits: a run cut short is simply started again.
 *
 * @internal
 */
#[Description('Delete the Audit log events older than kami.audit.retention months')]
#[Signature('kami:audit:prune')]
final class PruneAuditCommand extends Command
{
    private const int CHUNK = 1000;

    public function handle(): int
    {
        $months = Config::integer('kami.audit.retention', 12);

        // A typo deleting the whole log would erase the trail of an incident.
        if ($months < 1) {
            $this->components->error('kami.audit.retention must be a number of months, 1 or more.');

            return self::FAILURE;
        }

        $before = now()->subMonths($months);
        $deleted = 0;

        do {
            $chunk = AuditEvent::query()->toBase()->where('occurred_at', '<', $before)->limit(self::CHUNK)->delete();
            $deleted += $chunk;
        } while ($chunk === self::CHUNK);

        $this->components->info("Deleted {$deleted} audit " . Str::plural('event', $deleted) . " older than {$months} " . Str::plural('month', $months) . '.');

        return self::SUCCESS;
    }
}
