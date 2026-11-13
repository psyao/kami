<?php

declare(strict_types=1);

namespace Psyao\Kami\Audit;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Psyao\Kami\Audit\Actions\RecordAuditEvent;

/**
 * Groups the Audit events of a long run, such as an import, into one summary event (ADR 0022). Only the Audit log is grouped: Content events still fire for every item.
 */
final class AuditContext
{
    private static ?self $current = null;

    private ?self $outer = null;

    /** @var array<string, int> */
    private array $counts = [];

    /**
     * Runs the callback, recording no Audit event per item, then records one summary event with the context and the count of each event recorded inside. A callback that throws records nothing. A batch inside another counts as one event of the outer one.
     *
     * @template T
     *
     * @param  string  $event  The summary's name, such as `acme.import.completed`.
     * @param  callable(): T  $callback
     * @param  array<string, scalar|null>  $context  As `RecordAuditEvent`'s, checked before the callback runs; the counts are not held to its 20 keys.
     * @return T
     *
     * @throws InvalidArgumentException When the context breaks `RecordAuditEvent`'s rules, before the callback runs.
     */
    public static function batch(string $event, callable $callback, array $context = []): mixed
    {
        RecordAuditEvent::refuseLongContext($context);

        $batch = self::open();

        try {
            $result = $callback();
        } finally {
            $counts = $batch->close();
        }

        resolve(RecordAuditEvent::class)->summarise($event, [...$context, ...$counts]);

        return $result;
    }

    /**
     * @internal
     *
     * Opens a batch for `Import`, one per write: Audit events count towards it until `close()`.
     */
    public static function open(): self
    {
        $batch = new self;
        $batch->outer = self::$current;
        self::$current = $batch;

        return $batch;
    }

    /**
     * @internal
     *
     * Hands the current batch back to the one it was opened in, and returns the count of each event recorded inside; the caller records the summary.
     *
     * @return array<string, int>
     */
    public function close(): array
    {
        self::$current = $this->outer;

        return array_filter($this->counts);
    }

    /**
     * @internal
     *
     * Counts the event towards the current batch, and tells whether there is one. A rolled back transaction takes its events back out.
     */
    public static function absorb(string $event): bool
    {
        $batch = self::$current;

        if (! $batch instanceof self) {
            return false;
        }

        $batch->counts[$event] = ($batch->counts[$event] ?? 0) + 1;
        DB::afterRollBack(function () use ($batch, $event): void {
            $batch->counts[$event]--;
        });

        return true;
    }
}
