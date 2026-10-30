<?php

declare(strict_types=1);

namespace Workbench\App\Content;

use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Fields\Boolean;
use Psyao\Kami\Content\Fields\Text;
use Workbench\App\Content\Fields\Colour;

/** A translatable type under a Prefix, as a blog's posts are. */
final class Article extends ContentType
{
    #[\Override]
    protected ?string $prefix = 'articles';

    public function fields(): array
    {
        return [
            Text::make('summary')->rules(['max:200']),
            Text::make('author')->shared()->required(),
            Boolean::make('featured'),
            Colour::make('accent'),
        ];
    }
}
