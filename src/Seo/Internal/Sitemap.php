<?php

declare(strict_types=1);

namespace Psyao\Kami\Seo\Internal;

use Closure;
use Illuminate\Support\Facades\File;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Routing\Internal\PageCache;
use Psyao\Kami\Sites\Internal\Site;
use Psyao\Kami\Support\Internal\Install\RobotsTxt;
use RuntimeException;
use Throwable;
use XMLWriter;

/**
 * The static sitemap Apache serves without PHP (ADR 0027): `public/sitemap.xml`, always an index, over `public/sitemaps/{locale}-{n}.xml`.
 * It is rebuilt whole when stale: after a core write, which touches a marker under `storage/framework/kami/`, or once a visibility change the page cache manifest records has passed.
 *
 * ponytail: the `default` Site only, as `Site::current()`; V1 multisite needs a per-host index (ADR 0027, Multi-site door).
 * ponytail: Entries only; M10 adds Archives and Term Archives.
 *
 * @internal
 */
final class Sitemap
{
    public const int URLS_PER_FILE = 10_000;

    private const string NAMESPACE = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    private const int BATCH = 500;

    /** Touched after each core write that may change what the sitemap lists or its `lastmod`; never moved back. */
    public static function markStale(?int $at = null): void
    {
        $at ??= now()->getTimestamp();

        // Like the page cache's manifest: the write it follows has committed, so a failure is reported, never thrown.
        rescue(function () use ($at): void {
            File::ensureDirectoryExists(dirname($marker = self::state('stale')));
            clearstatcache(true, $marker);

            if ((int) @filemtime($marker) < $at) {
                touch($marker, $at);
            }
        });
    }

    /** Never built for this Site URL, its files gone, or changed since the last build. */
    public static function isStale(): bool
    {
        return self::built() === null || ! is_dir(public_path('sitemaps')) || self::staleSince() !== null;
    }

    /** The earliest change since the last build, as a Unix timestamp; null while fresh or before any build. */
    public static function staleSince(): ?int
    {
        $built = self::built();

        if ($built === null) {
            return null;
        }

        clearstatcache(true, self::state('stale'));
        $changes = array_filter([(int) @filemtime(self::state('stale')), PageCache::passedChange() ?? 0], fn (int $at): bool => $at >= $built);

        return $changes === [] ? null : min($changes);
    }

    /**
     * Rebuilds every file into a temporary directory, swapped in by rename, so a crawler never reads half a sitemap.
     * The index is left out when a route of the application answers `/sitemap.xml` (ADR 0038). One build runs at a time.
     *
     * @return int The URLs written.
     */
    public static function build(): int
    {
        return self::locked(self::write(...));
    }

    /**
     * Builds unless `$built`, asked once the lock is held, says a build that ran meanwhile wrote what the caller needs (#441).
     *
     * @param  Closure(): bool  $built
     */
    public static function buildUnless(Closure $built): void
    {
        self::locked(function () use ($built): void {
            clearstatcache();

            if (! $built()) {
                self::write();
            }
        });
    }

    /**
     * Runs the callback holding an exclusive `flock()` on a file under `storage/framework/kami/`, waiting for it: a lock any local disk gives, with no cache store or process.
     * When the lock file cannot be opened, the callback runs unlocked rather than not at all.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    private static function locked(Closure $callback): mixed
    {
        $handle = rescue(function () {
            File::ensureDirectoryExists(dirname($lock = self::state('lock')));

            return fopen($lock, 'c');
        }, false);

        if ($handle === false) {
            return $callback();
        }

        flock($handle, LOCK_EX);

        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return int The URLs written. */
    private static function write(): int
    {
        $site = Site::current();
        $started = now()->getTimestamp();
        self::deleteLeftovers();
        File::ensureDirectoryExists($directory = public_path('sitemaps.' . bin2hex(random_bytes(4))));
        /** @var array<string, array{writer: XMLWriter, number: int, urls: int}> $files By Locale: the open file, its number, its URLs. */
        $files = [];
        $total = 0;

        try {
            $routable = array_map(fn (ContentType $type): string => $type->handle(), array_filter(resolve(ContentTypes::class)->all(), fn (ContentType $type): bool => $type->isRoutable()));

            // Every Translation's visibility is read in PHP, as `url()` does, rather than once per Locale in SQL.
            foreach (Entry::query()->withUnpublished()->where('site', $site->handle)->whereIn('type', $routable)->lazyById(self::BATCH) as $entry) {
                $urls = ResolvePageMeta::indexableUrls($site, $entry);
                $alternates = ResolvePageMeta::alternates($site, $urls);

                foreach ($urls as $locale => $url) {
                    if (($files[$locale]['urls'] ?? self::URLS_PER_FILE) === self::URLS_PER_FILE) {
                        if (isset($files[$locale])) {
                            self::close($files[$locale]['writer']);
                        }
                        $number = ($files[$locale]['number'] ?? 0) + 1;
                        $files[$locale] = ['writer' => self::open("{$directory}/{$locale}-{$number}.xml"), 'number' => $number, 'urls' => 0];
                    }

                    self::url($files[$locale]['writer'], $url, $entry->translation($locale)?->updated_at?->toAtomString(), $alternates);
                    $files[$locale]['urls']++;
                    $total++;
                }
            }

            foreach ($files as $file) {
                self::close($file['writer']);
            }
        } catch (Throwable $exception) {
            File::deleteDirectory($directory);

            throw $exception;
        }

        self::swap($directory);

        if (! RobotsTxt::hostRouteAnswers('/sitemap.xml')) {
            File::replace(public_path('sitemap.xml'), self::index());
        }

        // The Site URL it was built for: a new one makes every `loc` stale.
        File::ensureDirectoryExists(dirname($built = self::state('built')));
        File::put($built, $site->url);
        touch($built, $started);

        return $total;
    }

    /**
     * The absolute URLs of the per-Locale files, built first when missing, for a host application's own index (ADR 0038).
     *
     * @return list<string>
     */
    public static function urls(): array
    {
        if (! is_dir(public_path('sitemaps'))) {
            self::buildUnless(fn (): bool => is_dir(public_path('sitemaps')));
        }

        $site = Site::current();
        $urls = [];

        foreach ($site->locales as $locale) {
            for ($number = 1; is_file(public_path("sitemaps/{$locale}-{$number}.xml")); $number++) {
                $urls[] = mb_rtrim($site->url, '/') . "/sitemaps/{$locale}-{$number}.xml";
            }
        }

        return $urls;
    }

    private static function open(string $file): XMLWriter
    {
        $writer = new XMLWriter;

        if (! $writer->openUri($file)) {
            throw new RuntimeException("The sitemap file {$file} cannot be written.");
        }

        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElement('urlset');
        $writer->writeAttribute('xmlns', self::NAMESPACE);
        $writer->writeAttribute('xmlns:xhtml', 'http://www.w3.org/1999/xhtml');

        return $writer;
    }

    /** @param  array<string, string>  $alternates */
    private static function url(XMLWriter $writer, string $url, ?string $lastmod, array $alternates): void
    {
        $writer->startElement('url');
        $writer->writeElement('loc', $url);

        if ($lastmod !== null) {
            $writer->writeElement('lastmod', $lastmod);
        }

        foreach ($alternates as $hreflang => $href) {
            $writer->startElement('xhtml:link');
            $writer->writeAttribute('rel', 'alternate');
            $writer->writeAttribute('hreflang', $hreflang);
            $writer->writeAttribute('href', $href);
            $writer->endElement();
        }

        $writer->endElement();
        // Streamed: memory holds one URL, whatever the file's size.
        $writer->flush();
    }

    private static function close(XMLWriter $writer): void
    {
        $writer->endElement();
        $writer->endDocument();
        $writer->flush();
    }

    private static function index(): string
    {
        $writer = new XMLWriter;
        $writer->openMemory();
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElement('sitemapindex');
        $writer->writeAttribute('xmlns', self::NAMESPACE);

        foreach (self::urls() as $url) {
            $writer->startElement('sitemap');
            $writer->writeElement('loc', $url);
            $writer->endElement();
        }

        $writer->endElement();
        $writer->endDocument();

        return $writer->outputMemory();
    }

    /** Two renames, each atomic: a build running at the same time that loses the race drops its own files. */
    private static function swap(string $directory): void
    {
        $current = public_path('sitemaps');
        $old = "{$current}." . bin2hex(random_bytes(4)) . '.old';
        $moved = is_dir($current) && @rename($current, $old);

        if (! @rename($directory, $current)) {
            File::deleteDirectory($directory);

            if (! is_dir($current)) {
                throw new RuntimeException("The sitemap cannot be moved to {$current}.");
            }
        }

        if ($moved) {
            File::deleteDirectory($old);
        }
    }

    /** What a build stopped by a time limit left behind, an hour on. */
    private static function deleteLeftovers(): void
    {
        foreach (glob(public_path('sitemaps.*'), GLOB_ONLYDIR) ?: [] as $leftover) {
            if ((int) @filemtime($leftover) < now()->getTimestamp() - 3600) {
                File::deleteDirectory($leftover);
            }
        }
    }

    private static function state(string $name): string
    {
        return storage_path("framework/kami/sitemap-{$name}");
    }

    /** When the last build for the current Site URL started. */
    private static function built(): ?int
    {
        $file = self::state('built');
        clearstatcache(true, $file);

        return @file_get_contents($file) === Site::current()->url ? ((int) filemtime($file) ?: null) : null;
    }
}
