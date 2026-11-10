<?php

declare(strict_types=1);

namespace Psyao\Kami\Forms\Internal;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * The backstop of Submission delivery, every 15 minutes in `Kami::schedule()`: retries each channel that failed after the response,
 * or that `defer()` never reached. A batch per run, within a time budget, so a Tick on shared hosting stays short even when the mail
 * server hangs; a delivery is abandoned after its fifth attempt.
 *
 * @internal
 */
#[Description('Deliver the Form Submissions whose notification failed or was never sent')]
#[Signature('kami:forms:deliver')]
final class DeliverSubmissionsCommand extends Command
{
    private const int BATCH = 50;

    /** Seconds after which no delivery starts: under the web Tick's 40, as each may wait for its mail server's timeout. */
    private const int BUDGET = 20;

    public function handle(): int
    {
        $deadline = now()->addSeconds(self::BUDGET);
        $pending = Delivery::query()->pending()->with('submission')
            // Not one that `defer()` may be sending right now.
            ->where('updated_at', '<=', now()->subMinute())
            ->orderBy('updated_at')
            ->limit(self::BATCH)
            ->get();
        $tried = $delivered = 0;

        foreach ($pending as $delivery) {
            if (now()->greaterThanOrEqualTo($deadline)) {
                break;
            }

            $tried++;
            $delivered += (int) Deliveries::send($delivery);
        }

        $this->components->info("Delivered {$delivered} of {$tried} pending " . Str::plural('delivery', $tried) . '.' . ($tried < $pending->count() ? ' Out of time: the rest waits for the next run.' : ''));

        return self::SUCCESS;
    }
}
