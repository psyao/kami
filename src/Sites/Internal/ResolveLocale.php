<?php

declare(strict_types=1);

namespace Psyao\Kami\Sites\Internal;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reads the current Locale from the first segment of the path, the default Locale having none, and makes it the application locale.
 *
 * @internal
 */
final class ResolveLocale
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        self::apply($request);

        return $next($request);
    }

    /** Also run by `Kami::exceptions()` before an error view renders, on a route of the host's or a request no route answered. */
    public static function apply(Request $request): void
    {
        $site = Site::current();
        $segment = $request->segment(1);
        $locale = in_array($segment, array_slice($site->locales, 1), true) ? $segment : $site->defaultLocale();

        Site::useLocale($locale);
        app()->setLocale($locale);
    }
}
