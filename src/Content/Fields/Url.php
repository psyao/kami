<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Fields;

/** A web address: `http` or `https` only, since a view prints it in a link. */
final class Url extends Field
{
    #[\Override]
    protected bool $translatable = false;

    #[\Override]
    protected function baseRules(): array
    {
        return ['url:http,https', 'max:255'];
    }
}
