<?php

declare(strict_types=1);

namespace Workbench\App\Content;

use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Fields\Text;

/** An untranslatable type: one `'*'` Translation, read in every Locale. */
final class Label extends ContentType
{
    #[\Override]
    protected bool $translatable = false;

    public function fields(): array
    {
        return [
            Text::make('colour'),
        ];
    }
}
