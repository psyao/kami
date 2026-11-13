<?php

declare(strict_types=1);

namespace Psyao\Kami\Forms\Internal;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;
use Psyao\Kami\Scheduling\Internal\Tick;

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

    /** Seconds after which no delivery starts outside a Tick; inside one, its remaining budget if shorter. Each send may then wait for its mailer's timeout. */
    private const int BUDGET = 20;

    public function handle(): int
    {
        $deadline = now()->addMilliseconds((int) (min(self::BUDGET, Tick::secondsLeft() ?? self::BUDGET) * 1000));
        $due = Delivery::query()->due()->with('submission')->orderBy('updated_at')->limit(self::BATCH)->get();
        $tried = $delivered = 0;
        $outOfTime = false;

        foreach ($due as $delivery) {
            if ($outOfTime = now()->greaterThanOrEqualTo($deadline)) {
                break;
            }

            $sent = Deliveries::send($delivery);
            $tried += (int) ($sent !== null);
            $delivered += (int) $sent;
        }

        $waiting = Delivery::query()->waiting()->count();
        $this->components->info("Delivered {$delivered} of {$tried} pending " . Str::plural('delivery', $tried) . '.'
            . ($outOfTime ? ' Out of time: the rest waits for the next run.' : '')
            . ($waiting > 0 ? " {$waiting} waiting for " . ($waiting === 1 ? 'its' : 'their') . " next try {$this->nextRun()}." : ''));

        return self::SUCCESS;
    }

    /** When this command runs next, as the site's schedule has it, `Kami::schedule()` or its own: in UTC, named, as `kami:doctor` prints times. */
    private function nextRun(): string
    {
        $event = collect(resolve(Schedule::class)->events())->first(fn (Event $event): bool => Str::endsWith((string) $event->command, ' ' . $this->getName()));

        return $event instanceof Event ? 'at ' . $event->nextRunDate(now())->utc()->format('H:i \U\T\C') : 'at the next run';
    }
}
