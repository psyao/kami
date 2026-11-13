<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal;

use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as FilamentLogin;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Psyao\Kami\Audit\Actions\RecordAuditEvent;

/**
 * Filament's sign-in page, throttled per lower-cased email as well as per IP address, so guessing a password is slow even from many addresses, and recording a failed MFA challenge after the right password in the Audit log.
 *
 * @internal
 */
final class Login extends FilamentLogin
{
    /**
     * Records a failed MFA challenge, a wrong or blank code after the right password: Filament fires no auth event for it, and it says the password is known (ADR 0022).
     *
     * @throws ValidationException
     */
    public function authenticate(): ?LoginResponse
    {
        // Filament validates a filled `code` even once the recovery field shows, after the recovery rule has spent its code: a wrong code left there
        // would refuse the sign-in yet use the recovery code up (#384). The recovery code alone decides.
        // ponytail: while `filamentphp/filament` validates both fields; V1 drops it once Filament's `code` rule skips a recovery attempt.
        if (is_array($challenge = $this->data['multiFactor'] ?? null)) {
            foreach ($challenge as $provider => $state) {
                if (is_array($state) && ($state['useRecoveryCode'] ?? false) === true) {
                    $this->data['multiFactor'][$provider] = [...$state, 'code' => null];
                }
            }
        }

        try {
            return parent::authenticate();
        } catch (ValidationException $exception) {
            // Errors under the challenge form's state path, Filament's `data.multiFactor`; a wrong password at the code step clears the challenge before it throws, and errs on the email instead.
            if (($user = $this->getUserUndertakingMultiFactorAuthentication()) instanceof Model
                && array_any(array_keys($exception->errors()), fn (string $key): bool => str_starts_with($key, 'data.multiFactor.'))) {
                resolve(RecordAuditEvent::class)->handle('kami.mfa.challenge-failed', $user);
            }

            throw $exception;
        }
    }

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
