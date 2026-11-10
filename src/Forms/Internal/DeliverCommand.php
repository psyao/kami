<?php

declare(strict_types=1);

namespace Psyao\Kami\Forms\Internal;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * The backstop of Submission delivery, every 15 minutes in `Kami::schedule()`: retries each channel that failed after the response,
 * or that `defer()` never reached. A batch per run, so a Tick on shared hosting stays short; a delivery is abandoned after its fifth attempt.
 *
 * @internal
 */
#[Description('Deliver the Form Submissions whose notification failed or was never sent')]
#[Signature('kami:forms:deliver')]
final class DeliverCommand extends Command
{
    private const int BATCH = 50;

    public function handle(): int
    {
        $pending = Delivery::query()->with('submission')
            ->whereNull('delivered_at')
            ->where('attempts', '<', Delivery::MAX_ATTEMPTS)
            // Not one that `defer()` may be sending right now.
            ->where('updated_at', '<=', now()->subMinute())
            ->oldest('updated_at')
            ->limit(self::BATCH)
            ->get();

        $delivered = $pending->filter(Deliveries::send(...))->count();

        $this->components->info("Delivered {$delivered} of {$pending->count()} pending " . Str::plural('delivery', $pending->count()) . '.');

        return self::SUCCESS;
    }
}
