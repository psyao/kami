<?php

declare(strict_types=1);

namespace Psyao\Kami\Routing\Internal;

use Closure;
use Illuminate\Http\Request;
use Psyao\Kami\Sites\Internal\Site;
use Symfony\Component\HttpFoundation\Response;

/**
 * Writes the page cache, and serves it too when the pre-boot reader is not installed (ADR 0021). First in the `kami` group, so what it stores carries the front's security headers.
 * A miss is stored from `terminate()`, as it was sent: Livewire injects its script and style on `RequestHandled`, once this middleware has returned.
 *
 * @internal
 */
final class CachePages
{
    /** Set on the request by a miss `handle()` let through, so `terminate()` never stores a hit again. */
    private const string MISS = 'kami.page_cache_miss';

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->cacheable($request)) {
            return $next($request);
        }

        if (($hit = PageCache::hit($request)) instanceof Response) {
            return $hit;
        }

        $request->attributes->set(self::MISS, true);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($request->attributes->get(self::MISS) === true && $this->storable($request, $response)) {
            PageCache::store($request, $response);
        }
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
            // An Inertia request for part of the page, or otherwise changing its answer: only `X-Inertia` and `X-Inertia-Version` name a whole page.
            && preg_grep('/^x-inertia-(?!version$)/', array_keys($request->headers->all())) === []
            && $request->getHost() === parse_url(Site::current()->url, PHP_URL_HOST)
            && in_array($request->route()?->getControllerClass(), [RenderEntry::class, RenderFeed::class], true);
    }

    private function storable(Request $request, Response $response): bool
    {
        $inertiaRequest = (bool) $request->headers->get('X-Inertia');
        $inertiaAnswer = $response->headers->get('X-Inertia') === 'true';

        // HEAD reaches here with its body already dropped; redirects and 4xx are never stored; a cookie, ours or the outer middleware's, marks a response for one visitor;
        // Inertia's JSON is stored for an Inertia request only, and an Inertia request stores nothing else: a page outside Inertia answers it from Laravel each time.
        return $request->isMethod('GET')
            && $inertiaRequest === $inertiaAnswer
            && (! $inertiaRequest || $this->answersItsVersion($request, $response))
            && $response->getStatusCode() === 200
            && $response->getContent() !== false
            && $response->headers->getCookies() === []
            && $request->attributes->get(PageCache::SKIP) !== true;
    }

    /**
     * Whether Inertia answered the asset version the request names, under which its JSON is keyed: a route without Inertia's middleware answers any version,
     * and a forged one must not fill the disk.
     */
    private function answersItsVersion(Request $request, Response $response): bool
    {
        $page = json_decode((string) $response->getContent(), true);

        return is_array($page) && ($page['version'] ?? null) === $request->headers->get('X-Inertia-Version', '');
    }
}
