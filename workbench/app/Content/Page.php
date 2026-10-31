<?php

declare(strict_types=1);

namespace Workbench\App\Content;

use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Fields\Textarea;

/** A translatable type at the root of the Site, with no Prefix, in a tree ordered by hand. */
final class Page extends ContentType
{
    #[\Override]
    protected ?string $prefix = '';

    #[\Override]
    protected bool $orderable = true;

    #[\Override]
    protected bool $hierarchical = true;

    public function fields(): array
    {
        return [
            Textarea::make('body'),
        ];
    }
}
