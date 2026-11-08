<?php

declare(strict_types=1);

namespace Psyao\Kami\Support\Internal;

use Closure;
use Illuminate\Http\Request;
use Psyao\Kami\Support\SecurityHeaders;
use Symfony\Component\HttpFoundation\Response;

/**
 * `SecurityHeaders`' front profile on Kami's public routes, with `X-Robots-Tag` while the Site is not indexable, under a class of its own: Kami's route drops the `web` group's
 * middleware, and Laravel drops a class (or a subclass) named there whatever its parameters, while a site appends `SecurityHeaders` to `web`.
 *
 * @internal
 */
final readonly class FrontSecurityHeaders
{
    public function __construct(private SecurityHeaders $headers) {}

    /**
     * @param  Closure(Request): Response  $next
     * @param  string  ...$overrides  `Name=value` in place of the front's header, for the admin Preview and Preview links (ADR 0018).
     */
    public function handle(Request $request, Closure $next, string ...$overrides): Response
    {
        $response = $this->headers->handle($request, $next);

        // Before the overrides, under `CachePages`: the page cache stores and replays it like the others (ADR 0027).
        if (! config('kami.indexable')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        foreach ($overrides as $override) {
            [$name, $value] = explode('=', $override, 2);
            $response->headers->set($name, $value);
        }

        return $response;
    }
}
