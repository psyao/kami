<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal;

use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Pages\Login as FilamentLogin;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Filament's sign-in page, throttled per lower-cased email as well as per IP address, so guessing a password is slow even from many addresses.
 *
 * @internal
 */
final class Login extends FilamentLogin
{
    /**
     * Filament calls it first thing on each attempt, the MFA code's included, with its limit per IP address: the same limit applies per email.
     *
     * @param  int  $maxAttempts
     * @param  int  $decaySeconds
     * @param  string|null  $method
     * @param  string|null  $component
     *
     * @throws TooManyRequestsException
     */
    protected function rateLimit($maxAttempts, $decaySeconds = 60, $method = null, $component = null): void
    {
        $email = $this->data['email'] ?? null;
        // Hashed: the `database` cache store keeps its keys in a table, and no address belongs there. The IP key is Filament's, from an untyped trait method.
        /** @var array{ip: string, email: string} $keys */
        $keys = [
            'ip' => $this->getRateLimitKey('authenticate'),
            'email' => 'kami-sign-in:' . hash('sha256', Str::lower(is_string($email) ? $email : '')),
        ];

        foreach ($keys as $kind => $key) {
            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                // The window's first refused attempt fires one `Lockout`; the next ones find the count past the limit, so refused requests write no row each.
                if (RateLimiter::attempts($key) === $maxAttempts) {
                    RateLimiter::hit($key, $decaySeconds);
                    // The core's `Lockout` listener records the kind; the event itself carries the request only.
                    request()->attributes->set('kami.throttled', $kind);
                    event(new Lockout(request()));
                }

                throw new TooManyRequestsException(self::class, 'authenticate', (string) request()->ip(), RateLimiter::availableIn($key));
            }
        }

        foreach ($keys as $key) {
            RateLimiter::hit($key, $decaySeconds);
        }
    }
}
