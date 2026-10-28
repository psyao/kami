<?php

declare(strict_types=1);

namespace Workbench\App\Content;

use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Fields\Text;

/** A translatable type under a Prefix, as a blog's posts are. */
final class Article extends ContentType
{
    #[\Override]
    protected ?string $prefix = 'articles';

    public function fields(): array
    {
        return [
            Text::make('summary'),
        ];
    }
}
