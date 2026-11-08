<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps every page of a Kami panel out of search engines, whatever `kami.indexable` says (ADR 0027).
 *
 * @internal
 */
final class NoIndex
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
