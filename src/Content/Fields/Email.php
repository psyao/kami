<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Fields;

/** An email address. */
final class Email extends Field
{
    #[\Override]
    protected bool $translatable = false;

    #[\Override]
    protected function baseRules(): array
    {
        return ['email', 'max:255'];
    }
}
