<?php

declare(strict_types=1);

namespace Psyao\Kami\Routing\Internal;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Psyao\Kami\Content\Internal\Visibility;
use Psyao\Kami\Seo\Internal\Sitemap;
use Symfony\Component\HttpFoundation\Response;

/**
 * The page cache's files under `storage/framework/kami/pages/` (ADR 0021), kept readable by plain PHP for the pre-boot reader, `resources/page-cache.php`, which repeats this format, the hash and the expiry:
 *
 * - `manifest.json`: `{"version": "<16 hex>", "next_change_at": <Unix timestamp>|null}`; null means nothing is Scheduled, and a missing, unreadable or malformed manifest is unknown, never "nothing Scheduled" (#268);
 * - `{version}/{xxh128(host . request URI)}`: a first line of JSON, `{"headers": {...}, "etag": "\"...\""}`, then the body; a page expires one hour after its file was written.
 *
 * @internal
 */
final class PageCache
{
    /** Set on the request by `Kami::withoutPageCache()`. */
    public const string SKIP = 'kami.without_page_cache';

    /** The version read once per request: an unwritable `storage/` would otherwise recompute it, with a query, on every call. */
    private const string VERSION = 'kami.content_version';

    /** Repeated by `resources/page-cache.php`. */
    private const int MAX_AGE = 3600;

    public static function enabled(): bool
    {
        return config()->boolean('kami.page_cache.enabled');
    }

    /**
     * The Content version, read once per request; a missing, malformed or passed manifest is recomputed and rewritten first.
     * Null when no manifest can be written: nothing is cached then, as a version kept for one request would add files and cache keys on every one (#441).
     */
    public static function version(): ?string
    {
        $known = request()->attributes->get(self::VERSION);

        if (is_string($known) || $known === false) {
            return $known ?: null;
        }

        $manifest = self::manifest();

        if ($manifest === null || ($manifest['next_change_at'] ?? PHP_INT_MAX) <= now()->getTimestamp()) {
            self::invalidate();

            return self::version();
        }

        request()->attributes->set(self::VERSION, $manifest['version']);

        return $manifest['version'];
    }

    /**
     * The manifest as written, or null when it is missing, unreadable or malformed.
     *
     * @return array{version: string, next_change_at: int|null}|null
     */
    public static function manifest(): ?array
    {
        $manifest = json_decode((string) @file_get_contents(self::directory('manifest.json')), true);

        return is_array($manifest) && is_string($manifest['version'] ?? null) && array_key_exists('next_change_at', $manifest)
            && ($manifest['next_change_at'] === null || is_int($manifest['next_change_at']))
            ? ['version' => $manifest['version'], 'next_change_at' => $manifest['next_change_at']]
            : null;
    }

    /** The visibility change the manifest records, once it has passed, as a Unix timestamp. */
    public static function passedChange(): ?int
    {
        $next = self::manifest()['next_change_at'] ?? null;

        return $next !== null && $next <= now()->getTimestamp() ? $next : null;
    }

    /**
     * Makes every cached page and fragment stale: a new Content version, with the next visibility change recomputed, in one atomic write; old versions' files go after the response.
     * A failure is reported, never thrown, as the write it follows has committed; the next request finds the manifest unknown and tries again.
     * An unwritable directory skips the visibility query: `kami:doctor` reports it, and the request caches nothing.
     */
    public static function invalidate(): void
    {
        $version = bin2hex(random_bytes(8));

        // The manifest about to be rewritten may hold a change that has passed: the sitemap must still see it (#410).
        if (($passed = self::passedChange()) !== null) {
            Sitemap::markStale($passed);
        }

        $written = rescue(function () use ($version): bool {
            if (! File::isDirectory(self::directory())) {
                File::ensureDirectoryExists(self::directory());
                // Describes this machine's content: kept out of git, as Laravel does cache/, sessions/ and views/.
                File::put(dirname(self::directory()) . '/.gitignore', "*\n!.gitignore\n");
            }

            if (! is_writable(self::directory())) {
                return false;
            }

            File::replace(self::directory('manifest.json'), json_encode(['version' => $version, 'next_change_at' => Visibility::nextChange()?->getTimestamp()], JSON_THROW_ON_ERROR));
            defer(self::prune(...));

            return true;
        }, false);

        request()->attributes->set(self::VERSION, $written ? $version : false);
    }

    /**
     * Deletes the files of every version but the manifest's.
     *
     * ponytail: run after each new version and by `kami:cache:clear` only; M8's Tick schedules it as the backstop ADR 0021 names, for a `defer()` that never ran.
     */
    public static function prune(): void
    {
        $current = self::manifest()['version'] ?? null;

        foreach (glob(self::directory('*'), GLOB_ONLYDIR) ?: [] as $directory) {
            if (basename($directory) !== $current) {
                File::deleteDirectory($directory);
            }
        }
    }

    /** The fresh cached page for the request, answering 304 to a matching `If-None-Match`; null on a miss. */
    public static function hit(Request $request): ?Response
    {
        if (($version = self::version()) === null) {
            return null;
        }

        $path = self::pagePath($request, $version);
        $handle = @filemtime($path) > now()->getTimestamp() - self::MAX_AGE ? @fopen($path, 'rb') : false;

        if ($handle === false) {
            return null;
        }

        $meta = json_decode((string) fgets($handle), true);
        $body = stream_get_contents($handle);
        fclose($handle);

        if (! is_array($meta) || ! is_array($meta['headers'] ?? null) || ! is_string($meta['etag'] ?? null) || $body === false) {
            return null;
        }

        /** @var array<string, list<string>> $headers */
        $headers = $meta['headers'];
        $response = self::fresh(new Response($body, 200, $headers), $meta['etag']);
        $response->isNotModified($request);

        return $response;
    }

    /**
     * Stores the response, atomically, then marks it as a fresh hit; with no version, stores nothing.
     */
    public static function store(Request $request, Response $response): void
    {
        if (($version = self::version()) === null) {
            return;
        }

        $body = (string) $response->getContent();
        $etag = '"' . $version . '-' . hash('xxh128', $body) . '"';
        // Set per hit, or describing this one only.
        $headers = array_diff_key($response->headers->allPreserveCaseWithoutCookies(), array_flip(['Date', 'Cache-Control', 'ETag']));

        rescue(function () use ($request, $version, $headers, $etag, $body): void {
            $path = self::pagePath($request, $version);
            File::ensureDirectoryExists(dirname($path));
            File::replace($path, json_encode(['headers' => $headers, 'etag' => $etag], JSON_THROW_ON_ERROR) . "\n" . $body);
        });

        self::fresh($response, $etag);
    }

    private static function fresh(Response $response, string $etag): Response
    {
        // Browsers revalidate every time, so they never show a stale page; `private` keeps shared proxies from storing it.
        return $response->setEtag($etag)->setCache(['no_cache' => true]);
    }

    private static function pagePath(Request $request, string $version): string
    {
        return self::directory($version . '/' . hash('xxh128', $request->getHost() . $request->getRequestUri()));
    }

    private static function directory(string $file = ''): string
    {
        return storage_path('framework/kami/pages' . ($file === '' ? '' : "/{$file}"));
    }
}
