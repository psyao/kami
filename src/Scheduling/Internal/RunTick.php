<?php

declare(strict_types=1);

namespace Psyao\Kami\Scheduling\Internal;

use Illuminate\Http\Response;

/**
 * The web Tick, `/kami/tick/{token}`, called by a host's planner (ADR 0020). It works inside the request, so the planner's own failure report fires on `500 FAILED`.
 *
 * @internal
 */
final readonly class RunTick
{
    /** Seconds kept for booting and answering under `max_execution_time`. */
    private const int RESERVE = 15;

    /** At most, whatever the host allows. */
    private const int MAX_BUDGET = 40;

    public function __invoke(string $token): Response
    {
        // `KAMI_TICK_TOKEN=null` reads as null: no token either.
        $expected = config('kami.tick.token');
        abort_if(! is_string($expected) || $expected === '' || ! hash_equals($expected, $token), 404);

        $result = Tick::run('web', $this->budget());

        return response($result, $result === 'FAILED' ? 500 : 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    /** `max_execution_time` less the reserve, at least one second, as a lock of zero seconds never expires on some stores; 0 is no limit. */
    private function budget(): int
    {
        $limit = (int) ini_get('max_execution_time');

        return $limit > 0 ? max(1, min($limit - self::RESERVE, self::MAX_BUDGET)) : self::MAX_BUDGET;
    }
}
