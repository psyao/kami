<?php

declare(strict_types=1);

namespace Psyao\Kami\Routing\Internal;

use Closure;
use Illuminate\Http\Request;
use Psyao\Kami\Sites\Internal\Site;
use Symfony\Component\HttpFoundation\Response;

/**
 * Writes the page cache, and serves it too when the pre-boot reader is not installed (ADR 0021). First in the `kami` group, so what it stores carries the front's security headers.
 *
 * @internal
 */
final class CachePages
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->cacheable($request)) {
            return $next($request);
        }

        if (($hit = PageCache::hit($request)) instanceof Response) {
            return $hit;
        }

        $response = $next($request);

        if ($this->storable($request, $response)) {
            PageCache::store($request, $response);
        }

        return $response;
    }

    private function cacheable(Request $request): bool
    {
        // A forged `Host` must not fill the disk; a query string or credentials make a page of their own; a session is one visitor's, and the `web` group
        // around the `kami` group sets its cookie only once this middleware has returned (ADR 0038).
        return PageCache::enabled()
            && ! $request->hasSession()
            && $request->isMethodCacheable()
            && $request->getQueryString() === null
            && ! $request->headers->has('Authorization')
            && $request->getHost() === parse_url(Site::current()->url, PHP_URL_HOST)
            && $request->route()?->getControllerClass() === RenderEntry::class;
    }

    private function storable(Request $request, Response $response): bool
    {
        // HEAD reaches here with its body already dropped; redirects and 4xx are never stored; a cookie marks a response for one visitor.
        return $request->isMethod('GET')
            && $response->getStatusCode() === 200
            && $response->getContent() !== false
            && $response->headers->getCookies() === []
            && $request->attributes->get(PageCache::SKIP) !== true;
    }
}
