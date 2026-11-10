<?php

declare(strict_types=1);

namespace Psyao\Kami\Scheduling\Internal;

use Closure;
use Illuminate\Http\Request;
use Psyao\Kami\Support\Internal\WebEnvironment;
use Symfony\Component\HttpFoundation\Response;

/**
 * The visit Tick: after a front or admin response, when no Tick ran for five minutes, a short Tick runs once the response is sent,
 * so a site with no cron and no planner still runs its tasks (ADR 0020). Otherwise it refreshes the web PHP's snapshot once an hour. First in the `kami` group, so a page `CachePages` serves counts too.
 *
 * @internal
 */
final class TickOnVisit
{
    /** Seconds the visitor's PHP worker keeps working after the response. */
    private const int BUDGET = 10;

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->isMethod('HEAD') || $response->getStatusCode() >= 400) {
            return $response;
        }

        if (Tick::stale()) {
            // A cache store that cannot lock is reported, never thrown: the visitor already has the page.
            defer(fn () => rescue(fn (): string => Tick::run('visit', self::BUDGET)));
        } elseif (WebEnvironment::stale()) {
            // On a server, cron's Ticks keep visits from starting any: the web's PHP is still seen, once an hour, by a visit.
            defer(fn () => rescue(WebEnvironment::capture(...), report: false));
        }

        return $response;
    }
}
