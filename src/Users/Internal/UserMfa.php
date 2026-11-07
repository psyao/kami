<?php

declare(strict_types=1);

namespace Psyao\Kami\Users\Internal;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Psyao\Kami\Audit\Actions\RecordAuditEvent;
use SensitiveParameter;

/**
 * A User's authenticator in `kami_user_mfa`, behind `HasKamiMfa`: one row while MFA is set up, encrypted with the app key.
 *
 * @internal
 */
final class UserMfa
{
    /** @return array{secret: string, recovery_codes: ?array<string>}|null */
    public static function find(Model $user): ?array
    {
        $row = DB::table('kami_user_mfa')->where('user_id', $user->getKey())->first(['secret', 'recovery_codes']);

        if ($row === null) {
            return null;
        }

        /** @var array{secret: string, recovery_codes: ?string} $row */
        $row = (array) $row;
        /** @var ?array<string> $codes */
        $codes = $row['recovery_codes'] === null ? null : json_decode(Crypt::decryptString($row['recovery_codes']), true, flags: JSON_THROW_ON_ERROR);

        return ['secret' => Crypt::decryptString($row['secret']), 'recovery_codes' => $codes];
    }

    /** A secret sets MFA up or replaces it; `null` disables it, deleting the row and its recovery codes. */
    public static function saveSecret(Model $user, #[SensitiveParameter] ?string $secret): void
    {
        DB::transaction(function () use ($user, $secret): void {
            $rows = DB::table('kami_user_mfa')->where('user_id', $user->getKey());

            if ($secret === null) {
                if ($rows->delete() > 0) {
                    self::record('kami.mfa.disabled', $user);
                }
            } elseif ($rows->exists()) {
                $rows->update(['secret' => Crypt::encryptString($secret), 'updated_at' => now()]);
            } else {
                DB::table('kami_user_mfa')->insert(['user_id' => $user->getKey(), 'secret' => Crypt::encryptString($secret), 'created_at' => now(), 'updated_at' => now()]);
                self::record('kami.mfa.enabled', $user);
            }
        });
    }

    /** @param  ?array<string>  $codes  Hashed by Filament. */
    public static function saveRecoveryCodes(Model $user, #[SensitiveParameter] ?array $codes): void
    {
        DB::transaction(function () use ($user, $codes): void {
            $stored = self::find($user)['recovery_codes'] ?? null;

            DB::table('kami_user_mfa')->where('user_id', $user->getKey())->update([
                'recovery_codes' => $codes === null ? null : Crypt::encryptString(json_encode(array_values($codes), JSON_THROW_ON_ERROR)),
                'updated_at' => now(),
            ]);

            // Filament rewrites stored hashes only when a code is used: the same hashes, less that one. Set-up and regeneration hash new codes.
            if ($stored !== null && $codes !== null && count($codes) === count($stored) - 1 && array_diff($codes, $stored) === []) {
                self::record('kami.mfa.recovery-used', $user);
            }
        });
    }

    /** The User acts on its own account. Before `kami:install` has migrated the Audit log, nothing is recorded. */
    private static function record(string $event, Model $user): void
    {
        if (Schema::hasTable('kami_audit_events')) {
            resolve(RecordAuditEvent::class)->handle($event, $user, actor: $user instanceof Authenticatable ? $user : null);
        }
    }
}
