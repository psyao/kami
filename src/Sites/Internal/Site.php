<?php

declare(strict_types=1);

namespace Psyao\Kami\Sites\Internal;

/**
 * A Site declared in `config/kami.php`, and the one place the current Site and Locale are resolved (ADR 0004).
 *
 * @internal
 */
final readonly class Site
{
    private const string LOCALE = 'kami.locale';

    /** @param list<string> $locales The first is the default. */
    public function __construct(public string $handle, public string $url, public array $locales) {}

    // ponytail: the `default` Site only; multisite resolves it from the request here.
    public static function current(): self
    {
        /** @var array{url: string, locales: list<string>} $site */
        $site = config('kami.sites.default');

        return new self('default', $site['url'], $site['locales']);
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
