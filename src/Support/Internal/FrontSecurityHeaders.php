<?php

declare(strict_types=1);

namespace Psyao\Kami\Support\Internal;

use Closure;
use Illuminate\Http\Request;
use Psyao\Kami\Support\SecurityHeaders;
use Symfony\Component\HttpFoundation\Response;

/**
 * `SecurityHeaders`' front profile in the `kami` group, under a class of its own: Kami's route drops the `web` group's
 * middleware, and Laravel drops a class (or a subclass) named there whatever its parameters, while a site appends `SecurityHeaders` to `web`.
 *
 * @internal
 */
final readonly class FrontSecurityHeaders
{
    public function __construct(private SecurityHeaders $headers) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        return $this->headers->handle($request, $next);
    }
}
