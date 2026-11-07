<?php

declare(strict_types=1);

namespace Psyao\Kami\Audit;

use Illuminate\Support\Facades\DB;
use Psyao\Kami\Audit\Actions\RecordAuditEvent;

/**
 * Groups the Audit events of a long run, an import or a `ContentMigrator` operation, into one summary event (ADR 0022). Only the Audit log is grouped: Content events still fire for every item.
 */
final class AuditContext
{
    private static ?self $current = null;

    /** @var array<string, int> */
    private array $counts = [];

    /**
     * Runs the callback, recording no Audit event per item, then records one summary event with the context and the count of each event recorded inside. A callback that throws records nothing. A batch inside another counts as one event of the outer one.
     *
     * @template T
     *
     * @param  string  $event  The summary's name, such as `acme.import.completed`.
     * @param  callable(): T  $callback
     * @param  array<string, scalar|null>  $context  As `RecordAuditEvent`'s.
     * @return T
     */
    public static function batch(string $event, callable $callback, array $context = []): mixed
    {
        $outer = self::$current;
        $batch = self::$current = new self;

        try {
            $result = $callback();
        } finally {
            self::$current = $outer;
        }

        resolve(RecordAuditEvent::class)->handle($event, context: [...$context, ...array_filter($batch->counts)]);

        return $result;
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
