<?php

declare(strict_types=1);

namespace Workbench\App\Content;

use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Fields\Textarea;

/** A translatable type at the root of the Site, with no Prefix. */
final class Page extends ContentType
{
    #[\Override]
    protected ?string $prefix = '';

    public function fields(): array
    {
        return [
            Textarea::make('body'),
        ];
    }
}
