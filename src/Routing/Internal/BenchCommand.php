<?php

declare(strict_types=1);

namespace Psyao\Kami\Routing\Internal;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
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
        $url = rtrim(Site::current()->url, '/') . '/' . ltrim($this->argument('path'), '/');
        $client = Http::connectTimeout(3)->timeout(30)->withoutRedirecting();

        if (is_string($user = $this->option('user'))) {
            $client = $client->withDigestAuth($user, password("Password of {$user}"));
        }

        try {
            // A query string bypasses the page cache, in the reader and in the middleware alike.
            $medians = [
                'cached' => $this->median($client, array_fill(0, self::RUNS, $url)),
                'uncached' => $this->median($client, array_map(fn (int $run): string => "{$url}?kami-bench={$run}", range(1, self::RUNS))),
            ];
        } catch (ConnectionException | RuntimeException $exception) {
            $this->components->warn("Nothing measured: {$exception->getMessage()}");

            return self::SUCCESS;
        }

        foreach ($medians as $kind => $median) {
            $this->components->twoColumnDetail(ucfirst($kind), sprintf('%.1f ms', $median));

            if ($median > self::BUDGETS[$kind]) {
                $this->components->warn(sprintf('%s pages take %.1f ms, above the %d ms budget.', ucfirst($kind), $median, self::BUDGETS[$kind]));
            }
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
     * Server time as the client's transfer statistics give it: from the TLS handshake's end to the response's first byte.
     *
     * @param  list<string>  $urls
     */
    private function median(PendingRequest $client, array $urls): float
    {
        $times = [];

        foreach ($urls as $url) {
            $response = $client->get($url);

            if ($response->status() !== 200) {
                throw new RuntimeException("{$url} answers {$response->status()}.");
            }

            // cURL's, in seconds.
            $stats = $response->handlerStats();
            $times[] = (Arr::float($stats, 'starttransfer_time') - Arr::float($stats, 'appconnect_time')) * 1000;
        }

        return round((float) collect($times)->median(), 1);
    }

    /** @param array{cached: float, uncached: float} $medians */
    private function store(string $url, array $medians): void
    {
        try {
            File::ensureDirectoryExists(dirname(self::resultPath()));
            // What this machine measured: kept out of git, as `kami:doctor` keeps its report.
            File::put(dirname(self::resultPath()) . '/.gitignore', "*\n!.gitignore\n");
            File::replace(self::resultPath(), json_encode([
                'measured_at' => now()->toIso8601String(),
                'url' => $url,
                'cached_ms' => $medians['cached'],
                'uncached_ms' => $medians['uncached'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            $this->components->warn('The result could not be written to ' . self::resultPath() . '.');
        }
    }
}
