<?php

declare(strict_types=1);

namespace Psyao\Kami\Tests\Fixtures\Guide\Content;

use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Fields\Number;

/** The guides' `App\Content\Product`, which the Workbench Site lacks: a Translatable Field to filter on, a Shared one to sort by. */
final class Product extends ContentType
{
    public function fields(): array
    {
        return [
            Number::make('rank')->translatable()->filterable(),
            Number::make('stock')->sortable(),
        ];
    }
}
