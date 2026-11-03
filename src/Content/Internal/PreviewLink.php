<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use InvalidArgumentException;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Sites\Internal\Site;

/**
 * A Translation's saved state, readable without an account until it expires or is revoked (ADR 0019). Only the token's SHA-256 is stored.
 *
 * @internal
 *
 * @property string $id
 * @property string $entry_id
 * @property string $locale The Site Locale it renders in, an untranslatable type's `'*'` Translation included.
 * @property string $token_hash
 * @property CarbonImmutable $expires_at
 * @property int|string|null $created_by
 */
#[Unguarded]
#[Table(name: 'kami_preview_links')]
#[WithoutTimestamps]
final class PreviewLink extends Model
{
    use HasUlids;

    // ponytail: no schedule runs `model:prune` yet; M8's scheduler registers it with `--model`. Expiry is checked on read meanwhile.
    use Prunable;

    public const int MAX_DAYS = 30;

    /** Creates a link and returns its full URL, the only time the token exists outside the request. */
    public static function issue(Entry $entry, string $locale, int $days, ?Authenticatable $user): string
    {
        if ($days < 1 || $days > self::MAX_DAYS) {
            throw new InvalidArgumentException('A Preview link expires within 1 to ' . self::MAX_DAYS . ' days.');
        }

        $token = bin2hex(random_bytes(32));

        self::query()->create([
            'entry_id' => $entry->id,
            'locale' => $locale,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays($days),
            'created_by' => $user?->getAuthIdentifier(),
        ]);

        $site = Site::current();

        return $site->url($site->defaultLocale(), "kami/share/{$token}");
    }

    /** Deletes the link if it is one of this Translation's: the id comes from the browser. */
    public static function revoke(string $id, Entry $entry, string $locale): void
    {
        self::query()->whereKey($id)->where('entry_id', $entry->id)->where('locale', $locale)->delete();
    }

    /**
     * A Translation's links that have not expired, the soonest to expire first.
     *
     * @return Collection<int, self>
     */
    public static function activeFor(Entry $entry, string $locale): Collection
    {
        return self::unexpired()->where('entry_id', $entry->id)->where('locale', $locale)->oldest('expires_at')->get();
    }

    /** @return Builder<self> */
    public static function unexpired(): Builder
    {
        return self::query()->where('expires_at', '>', now());
    }

    /** @return Builder<self> */
    public function prunable(): Builder
    {
        return self::query()->where('expires_at', '<=', now());
    }

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime'];
    }
}
