<?php

declare(strict_types=1);

/*
 * Kami's pre-boot page cache reader (ADR 0021). One line of public/index.php, right after Laravel's maintenance check, requires it:
 *
 *     (require __DIR__ . '/../vendor/psyao/kami/resources/page-cache.php')(__DIR__ . '/../storage');
 *
 * It sends a fresh cached page with no autoload, no framework and no query, then exits; on anything else it returns and Laravel boots.
 * The files are PageCache's (src/Routing/Internal/PageCache.php), and CachePages serves them too when the line is missing.
 *
 * ponytail: no Tick check; M8's Tick adds here ADR 0021's check of the tick-* files, falling through when the last Tick is five minutes old.
 */
return static function (string $storage): void {
    $server = static fn (string $key): string => is_string($_SERVER[$key] ?? null) ? $_SERVER[$key] : '';
    $method = $server('REQUEST_METHOD');

    // What CachePages never stores; a query string is part of the URI, so its page is never found.
    if (! in_array($method, ['GET', 'HEAD'], true)
        || array_intersect_key($_SERVER, array_flip(['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'PHP_AUTH_USER', 'PHP_AUTH_DIGEST'])) !== []) {
        return;
    }

    $directory = $storage . '/framework/kami/pages/';
    $manifest = json_decode((string) @file_get_contents($directory . 'manifest.json'), true);

    // A missing, unreadable or malformed manifest is unknown (#268), and a passed visibility change makes every page stale: Laravel rewrites it.
    if (! is_array($manifest) || ! is_string($manifest['version'] ?? null) || ! array_key_exists('next_change_at', $manifest)
        || ! ($manifest['next_change_at'] === null || (is_int($manifest['next_change_at']) && $manifest['next_change_at'] > time()))) {
        return;
    }

    // The host as Laravel's Request::getHost() reads it: lower case, port stripped.
    $host = strtolower((string) preg_replace('/:\d+$/', '', trim($server('HTTP_HOST'))));
    $path = $directory . $manifest['version'] . '/' . hash('xxh128', $host . $server('REQUEST_URI'));
    // A page expires one hour after it was written.
    $handle = @filemtime($path) > time() - 3600 ? @fopen($path, 'rb') : false;
    $meta = $handle === false ? null : json_decode((string) fgets($handle), true);

    if (! is_array($meta) || ! is_array($meta['headers'] ?? null) || ! is_string($meta['etag'] ?? null)) {
        return;
    }

    foreach ($meta['headers'] as $name => $values) {
        foreach (array_filter((array) $values, is_string(...)) as $value) {
            header("{$name}: {$value}", false);
        }
    }

    header('ETag: ' . $meta['etag']);
    // Browsers revalidate every time, so they never show a stale page; `private` keeps shared proxies from storing it.
    header('Cache-Control: no-cache, private');

    // As Symfony's Response::isNotModified() reads If-None-Match.
    $tags = array_map(static fn (string $tag): string => str_starts_with($tag, 'W/') ? substr($tag, 2) : $tag, preg_split('/\s*,\s*/', $server('HTTP_IF_NONE_MATCH'), -1, PREG_SPLIT_NO_EMPTY) ?: []);

    if (in_array('*', $tags, true) || in_array($meta['etag'], $tags, true)) {
        http_response_code(304);
    } elseif ($method === 'GET') {
        fpassthru($handle);
    }

    exit;
};
