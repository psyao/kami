<?php

declare(strict_types=1);

// Drives a Filament page through Livewire over HTTP, as a browser does: required by the smoke's scripts, run from the site.

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

require 'vendor/autoload.php';

function fail(string $message): never
{
    fwrite(STDERR, "{$message}\n");
    exit(1);
}

// The CLI container shows no PHP errors: an HTTP failure (a 404 on the page, say) is named on stderr too.
set_exception_handler(fn (Throwable $exception) => fail($exception::class . ': ' . $exception->getMessage()));

/**
 * A browser's cookie jar for `http://apache`, which every request says it reached over HTTPS (`X-Forwarded-Proto`): the site's session cookie is `Secure`
 * in production, and Guzzle's own jar neither keeps nor sends one over a plain `http://` URL.
 */
final class HttpsCookieJar extends CookieJar
{
    public function extractCookies(RequestInterface $request, ResponseInterface $response): void
    {
        parent::extractCookies(self::overHttps($request), $response);
    }

    public function withCookieHeader(RequestInterface $request): RequestInterface
    {
        $cookies = parent::withCookieHeader(self::overHttps($request))->getHeaderLine('Cookie');

        return $cookies === '' ? $request : $request->withHeader('Cookie', $cookies);
    }

    private static function overHttps(RequestInterface $request): RequestInterface
    {
        return $request->withUri($request->getUri()->withScheme('https'), preserveHost: true);
    }
}

/** A Filament page, opened with its own session: its components by `memo.name`, and one Livewire call. */
final class LivewirePage
{
    private PendingRequest $http;

    private string $csrf;

    private string $update;

    /** @var list<string> */
    private array $snapshots;

    public function __construct(private string $base, string $path)
    {
        $this->http = (new Factory)->withHeaders(['X-Forwarded-Proto' => 'https'])->withOptions(['cookies' => new HttpsCookieJar])->throw();
        $page = $this->http->get($base . $path)->body();

        preg_match('/data-csrf="([^"]+)"/', $page, $csrf) || fail('No CSRF token on the page');
        preg_match('/data-update-uri="([^"]+)"/', $page, $update) || fail('No Livewire update URI on the page');
        preg_match_all('/wire:snapshot="([^"]+)"/', $page, $raw);
        [$this->csrf, $this->update] = [$csrf[1], (string) parse_url(html_entity_decode($update[1]), PHP_URL_PATH)];
        $this->snapshots = array_map(fn (string $snapshot): string => html_entity_decode($snapshot, ENT_QUOTES), $raw[1]);
    }

    /** One call on the component, as the page's script sends it, returning its first component's response. */
    public function call(string $name, array $updates, string $method, array $params = []): array
    {
        $snapshot = collect($this->snapshots)->first(fn (string $json): bool => data_get(json_decode($json, true), 'memo.name') === $name) ?? fail("No {$name} on the page");

        return $this->http->withHeaders(['X-Livewire' => '1'])->post($this->base . $this->update, [
            '_token' => $this->csrf,
            'components' => [['snapshot' => $snapshot, 'updates' => $updates, 'calls' => [['path' => '', 'method' => $method, 'params' => $params]]]],
        ])->json();
    }

    /** The notifications a call sent, as a visitor reads them: Filament keeps them in the session, and its notifications component pulls the batch. */
    public function notifications(array $response): string
    {
        $pulled = $this->call('Filament\Livewire\Notifications', [], 'pullNotifications', [[$response['filamentNotificationBatch'] ?? fail('No notification')]]);

        // Livewire dehydrates each notification as [its data, its type].
        return collect(json_decode($pulled['components'][0]['snapshot'], true)['data']['notifications'][0])
            ->map(fn (array $notification): string => trim($notification[0]['title'] . ' / ' . $notification[0]['body'], ' /'))->implode(' | ');
    }
}
