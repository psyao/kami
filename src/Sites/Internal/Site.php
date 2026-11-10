<?php

declare(strict_types=1);

namespace Psyao\Kami\Sites\Internal;

use Illuminate\Support\Str;

/**
 * A Site declared in `config/kami.php`, and the one place the current Site and Locale are resolved (ADR 0004).
 *
 * @internal
 */
final readonly class Site
{
    private const string LOCALE = 'kami.locale';

    /**
     * @param  list<string>  $locales  Their codes, the default first.
     * @param  string  $timezone  The one its dates are displayed in; they are stored in UTC.
     * @param  array<string, string>  $ogLocales  `og:locale` by Locale code, for the Locales with a region (`fr_CH`).
     */
    public function __construct(public string $handle, public string $url, public array $locales, public string $timezone, private array $ogLocales) {}

    // ponytail: the `default` Site only; V1 multisite resolves it from the request here.
    public static function current(): self
    {
        /** @var array{url: string, locales: array<int|string, string|array{region?: string}>, timezone: string} $site */
        $site = config('kami.sites.default');
        $locales = [];
        $ogLocales = [];

        // A Locale is a code (`'en'`) or a code with its options (`'fr' => ['region' => 'CH']`); a region may sit in the code (`fr-ch`).
        foreach ($site['locales'] as $key => $value) {
            [$code, $region] = is_string($value) ? [$value, null] : [(string) $key, $value['region'] ?? null];
            $parts = explode('-', $code);
            $region ??= count($parts) > 1 && strlen(end($parts)) === 2 ? end($parts) : null;
            $locales[] = $code;

            if ($region !== null && $region !== '') {
                $ogLocales[$code] = strtolower($parts[0]) . '_' . strtoupper($region);
            }
        }

        // A host is case-insensitive and the request's comes lower-cased: the configured one is too, so comparisons and printed URLs agree (#445).
        $host = (string) parse_url($site['url'], PHP_URL_HOST);

        return new self('default', Str::replaceFirst($host, strtolower($host), $site['url']), $locales, $site['timezone'], $ogLocales);
    }

    /** `og:locale` for a Locale with a region (`fr_CH`), `null` without one. */
    public function ogLocale(string $locale): ?string
    {
        return $this->ogLocales[$locale] ?? null;
    }

    public function defaultLocale(): string
    {
        return $this->locales[0];
    }

    /** Reads in the Locale `ResolveLocale` found in the request, or the default Locale outside one. */
    public function currentLocale(): string
    {
        $locale = app()->bound(self::LOCALE) ? resolve(self::LOCALE) : null;

        return in_array($locale, $this->locales, true) ? $locale : $this->defaultLocale();
    }

    /** The absolute URL of a stored Path in a Locale: the default Locale has no segment of its own. */
    public function url(string $locale, string $path): string
    {
        return implode('/', array_filter([rtrim($this->url, '/'), $locale === $this->defaultLocale() ? '' : $locale, $path], fn (string $part): bool => $part !== ''));
    }

    public static function useLocale(string $locale): void
    {
        app()->instance(self::LOCALE, $locale);
    }
}
