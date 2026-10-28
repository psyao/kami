<?php

declare(strict_types=1);

namespace Psyao\Kami\Support;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Vite;
use InvalidArgumentException;
use Psyao\Kami\Support\Internal\SecurityPolicy;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kami's Content Security Policy and companion headers (ADR 0018), on every response of its group, 4xx and 5xx included.
 *
 * The `front` profile (default) is fixed and nonce-free, so a cached page stays a static file; `kami.security.*` extends it.
 * The `admin` profile (`kami.security-headers:admin`) is Filament's minimum, with a per-request nonce on inline scripts.
 */
final class SecurityHeaders
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next, string $profile = 'front'): Response
    {
        [$policy, $headers] = match ($profile) {
            'front' => $this->front(),
            'admin' => $this->admin(),
            default => throw new InvalidArgumentException("SecurityHeaders has no profile {$profile}: use front or admin."),
        };

        if (Vite::isRunningHot()) {
            $policy = $this->withHotServer($policy);
        }

        $response = $next($request);

        header_remove('X-Powered-By');
        $response->headers->remove('X-Powered-By');

        $reportOnly = $profile === 'front' && Config::boolean('kami.security.csp_report_only');
        $response->headers->set($reportOnly ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy', $this->render($policy));

        // In order, so the site's `null` removes Kami's header whatever the case of its name.
        foreach ($headers as $name => $value) {
            $value === null ? $response->headers->remove($name) : $response->headers->set($name, $value);
        }

        return $response;
    }

    /**
     * The fixed front policy, with the site's sources merged in: an added source never removes a default, except `'none'`.
     *
     * @return array{array<string, list<string>>, array<string, string|null>}
     */
    private function front(): array
    {
        $policy = SecurityPolicy::FRONT;

        /** @var array<string, list<string>|string> $additions */
        $additions = Config::array('kami.security.csp', []);

        foreach ($additions as $directive => $sources) {
            $sources = (array) $sources;
            $kept = array_diff($policy[$directive] ?? [], $sources === [] ? [] : ["'none'"]);
            $policy[$directive] = array_values(array_unique([...$kept, ...$sources]));
        }

        $reportUri = Config::get('kami.security.csp_report_uri');

        if (is_string($reportUri) && $reportUri !== '') {
            $policy['report-uri'] = [$reportUri];
        }

        /** @var array<string, string|null> $overrides */
        $overrides = Config::array('kami.security.headers', []);

        return [$policy, [...SecurityPolicy::FRONT_HEADERS, ...$overrides]];
    }

    /** @return array{array<string, list<string>>, array<string, string|null>} */
    private function admin(): array
    {
        $policy = SecurityPolicy::ADMIN;
        // Filament and Livewire read it back from Vite to sign their inline scripts.
        array_splice($policy['script-src'], 1, 0, ["'nonce-" . Vite::useCspNonce() . "'"]);

        return [$policy, SecurityPolicy::ADMIN_HEADERS];
    }

    /**
     * @param  array<string, list<string>>  $policy
     * @return array<string, list<string>>
     */
    private function withHotServer(array $policy): array
    {
        $origin = mb_rtrim(trim((string) file_get_contents(Vite::hotFile())), '/');

        foreach (SecurityPolicy::HOT_DIRECTIVES as $directive) {
            $policy[$directive][] = $origin;
        }

        // Hot module replacement talks over a websocket on the same host.
        $policy['connect-src'][] = (string) preg_replace('#^http#', 'ws', $origin);

        return $policy;
    }

    /** @param  array<string, list<string>>  $policy */
    private function render(array $policy): string
    {
        return collect($policy)->map(fn (array $sources, string $directive): string => implode(' ', [$directive, ...$sources]))->implode('; ');
    }
}
