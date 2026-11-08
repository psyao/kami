<?php

declare(strict_types=1);

namespace Psyao\Kami\Facades;

use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Psyao\Kami\Sites\Internal\ResolveLocale;
use Throwable;

/**
 * Kami's entry point for sites.
 *
 * @method static void routes()
 * @method static mixed remember(string $key, \DateTimeInterface|\DateInterval|int $ttl, \Closure $callback)
 * @method static void withoutPageCache()
 * @method static void flushCache()
 * @method static list<string> sitemapUrls()
 *
 * @see \Psyao\Kami\Kami
 */
final class Kami extends Facade
{
    /**
     * Renders every error view in the Locale the URL's first segment names, on Kami's routes and the application's own, for `withExceptions()` in `bootstrap/app.php`.
     *
     * A method of the facade itself, never proxied: Laravel may resolve the exception handler before it sets the facades up, as for an error in a config file.
     */
    public static function exceptions(Exceptions $exceptions): void
    {
        // `null` lets Laravel render `errors/{code}` as usual, or the next callback the application registers.
        $exceptions->render(function (Throwable $e, Request $request): null {
            // An error while the application boots, a broken config file for one, renders as Laravel would: reading the Site then could replace it.
            if (app()->isBooted()) {
                ResolveLocale::apply($request);
            }

            return null;
        });
    }

    protected static function getFacadeAccessor(): string
    {
        return \Psyao\Kami\Kami::class;
    }
}
