<?php

declare(strict_types=1);

namespace Psyao\Kami\Routing\Internal;

use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Psyao\Kami\Sites\Internal\Site;
use RuntimeException;
use Throwable;

use function Laravel\Prompts\password;

/**
 * The median server time of a page served from the page cache and rendered by Laravel, measured from the host itself against
 * ADR 0021's budgets. It never fails, so that a deploy script can run it.
 *
 * @internal
 */
#[Description('Measure the server time of a page, from the page cache and rendered')]
#[Signature('kami:bench
    {path=/ : The path of the page, on the Site URL}
    {--user= : Sign in with Digest as this user, behind a staging password; the password is asked for}')]
final class BenchCommand extends Command
{
    private const int RUNS = 10;

    /** ADR 0021's budgets, in ms. */
    private const array BUDGETS = ['cached' => 30, 'uncached' => 200];

    public function handle(): int
    {
        $path = $this->input->getArgument('path');
        $url = rtrim(Site::current()->url, '/') . '/' . ltrim(is_string($path) ? $path : '', '/');
        $client = Http::connectTimeout(3)->timeout(30)->withoutRedirecting();

        if (is_string($user = $this->option('user'))) {
            $client = $client->withDigestAuth($user, password("Password of {$user}"));
        }

        try {
            // A query string bypasses the page cache, in the reader and in the middleware alike.
            $medians = [
                'cached' => $this->median($client, array_fill(0, self::RUNS, $url), fromPageCache: true),
                'uncached' => $this->median($client, array_map(fn (int $run): string => "{$url}?kami-bench={$run}", range(1, self::RUNS))),
            ];
        } catch (Throwable $exception) {
            $this->components->warn("Nothing measured: {$exception->getMessage()}");

            return self::SUCCESS;
        }

        foreach ($medians as $kind => $median) {
            $this->components->twoColumnDetail(ucfirst($kind), $median === null ? 'not served from the page cache' : sprintf('%.1f ms', $median));

            if ($median > self::BUDGETS[$kind]) {
                $this->components->warn(sprintf('%s pages take %.1f ms, above the %d ms budget.', ucfirst($kind), $median, self::BUDGETS[$kind]));
            }
        }

        if ($medians['cached'] === null) {
            $this->components->warn('No cached time is measured. Check that the page:');
            $this->components->bulletList([
                'is a Kami page, not a route of the site',
                'does not call Kami::withoutPageCache()',
                'receives no Authorization header, as a staging password sends',
            ]);
        }

        $this->store($url, $medians);

        return self::SUCCESS;
    }

    /** Where the last result lies, for `kami:doctor`. */
    public static function resultPath(): string
    {
        return storage_path('framework/kami/bench.json');
    }

    /**
     * The last result, or null when none was kept or the file was edited out of shape: `kami:doctor` never throws on it.
     * `cached_ms` is null when the page cache did not serve the page.
     *
     * @return array{measured_at: CarbonImmutable, url: string, cached_ms: ?float, uncached_ms: float}|null
     */
    public static function lastResult(): ?array
    {
        $result = json_decode((string) @file_get_contents(self::resultPath()), true);

        if (! is_array($result) || ! is_int($result['measured_at'] ?? null) || ! is_string($result['url'] ?? null) || ! array_key_exists('cached_ms', $result)
            || $result['cached_ms'] !== null && ! is_numeric($result['cached_ms']) || ! is_numeric($result['uncached_ms'] ?? null)) {
            return null;
        }

        return ['measured_at' => CarbonImmutable::createFromTimestamp($result['measured_at']), 'url' => $result['url'], 'cached_ms' => $result['cached_ms'] === null ? null : (float) $result['cached_ms'], 'uncached_ms' => (float) $result['uncached_ms']];
    }

    /**
     * Server time as the client's transfer statistics give it: from the request ready to send, past DNS, connect and TLS alike, to the response's first byte.
     * With `$fromPageCache`, null when any response does not carry the page cache's ETag: its time is a render, not a hit.
     *
     * @param  list<string>  $urls
     * @return ($fromPageCache is true ? float|null : float)
     */
    private function median(PendingRequest $client, array $urls, bool $fromPageCache = false): ?float
    {
        $hits = true;
        $times = [];

        foreach ($urls as $url) {
            $response = $client->get($url);

            if ($response->status() !== 200) {
                throw new RuntimeException("{$url} answers {$response->status()}.");
            }

            // cURL's, in seconds. PHP's stream wrapper, Guzzle's fallback without ext-curl, gives none.
            $stats = $response->handlerStats();

            if (! isset($stats['starttransfer_time'], $stats['pretransfer_time'])) {
                throw new RuntimeException('the HTTP client gives no transfer statistics: load the PHP extension curl.');
            }

            $hits = $hits && preg_match(PageCache::ETAG, $response->header('ETag')) === 1;
            $times[] = (Arr::float($stats, 'starttransfer_time') - Arr::float($stats, 'pretransfer_time')) * 1000;
        }

        return $fromPageCache && ! $hits ? null : round((float) collect($times)->median(), 1);
    }

    /** @param array{cached: ?float, uncached: float} $medians */
    private function store(string $url, array $medians): void
    {
        try {
            File::ensureDirectoryExists(dirname(self::resultPath()));
            // What this machine measured: kept out of git, as `kami:doctor` keeps its report.
            File::put(dirname(self::resultPath()) . '/.gitignore', "*\n!.gitignore\n");
            File::replace(self::resultPath(), json_encode([
                'measured_at' => now()->getTimestamp(),
                'url' => $url,
                'cached_ms' => $medians['cached'],
                'uncached_ms' => $medians['uncached'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            $this->components->warn('The result could not be written to ' . self::resultPath() . '.');
        }
    }
}
