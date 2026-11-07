<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin;

use Illuminate\Database\Eloquent\Model;
use Psyao\Kami\Users\Internal\UserMfa;
use SensitiveParameter;

/**
 * Filament's `HasAppAuthentication` and `HasAppAuthenticationRecovery` for an Eloquent User model, which declares both contracts; stored in `kami_user_mfa`, so the host's `users` table gains no column.
 *
 * Setting MFA up records `kami.mfa.enabled`, disabling it `kami.mfa.disabled`, and using a recovery code `kami.mfa.recovery-used`, in the Audit log.
 * The secret and the codes are encrypted with the app key: rotating `APP_KEY` without listing the old one in `APP_PREVIOUS_KEYS` makes them unreadable.
 *
 * @phpstan-require-extends Model
 */
trait HasKamiMfa
{
    /** @var array{secret: string, recovery_codes: ?array<string>}|false|null Read once per model instance, as Filament asks several times per request; `false` until read. */
    private array | false | null $kamiMfa = false;

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->kamiMfa()['secret'] ?? null;
    }

    /** A secret sets MFA up or replaces it; `null` disables it, recovery codes included. */
    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        UserMfa::saveSecret($this, $secret);
        $this->kamiMfa = false;
    }

    public function getAppAuthenticationHolderName(): string
    {
        $email = $this->getAttribute('email');

        return is_string($email) ? $email : '';
    }

    /** @return ?array<string> The hashed codes left. */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->kamiMfa()['recovery_codes'] ?? null;
    }

    /** @param  ?array<string>  $codes  Hashed by Filament. */
    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        UserMfa::saveRecoveryCodes($this, $codes);
        $this->kamiMfa = false;
    }

    /** @return array{secret: string, recovery_codes: ?array<string>}|null */
    private function kamiMfa(): ?array
    {
        if ($this->kamiMfa === false) {
            $this->kamiMfa = UserMfa::find($this);
        }

        return $this->kamiMfa;
    }
}
