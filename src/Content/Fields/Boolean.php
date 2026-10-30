<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Fields;

/** Yes or no, one value for the whole Entry unless `->translatable()`; `null` until given. */
final class Boolean extends Field
{
    #[\Override]
    protected bool $translatable = false;

    #[\Override]
    public function column(): string
    {
        return 'boolean';
    }

    #[\Override]
    protected function baseRules(): array
    {
        return ['boolean'];
    }

    #[\Override]
    protected function cast(mixed $value): bool
    {
        return (bool) $value;
    }
}
