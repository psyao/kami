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
    /** @param list<string> $locales The first is the default. */
    public function __construct(public string $handle, public array $locales) {}

    // ponytail: the `default` Site only; multisite resolves it from the request here.
    public static function current(): self
    {
        /** @var list<string> $locales */
        $locales = config('kami.sites.default.locales');

        return new self('default', $locales);
    }

    public function defaultLocale(): string
    {
        return $this->locales[0];
    }

    // ponytail: the default Locale until ResolveLocale (#97) reads it from the request.
    public function currentLocale(): string
    {
        return $this->defaultLocale();
    }
}
