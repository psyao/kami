<?php

declare(strict_types=1);

namespace Psyao\Kami\Content;

use Psyao\Kami\Content\Fields\Text;

/** The Site's own editable settings, always registered: read with `SiteSettings::entry()`, written with `SaveEntry` (ADR 0029). */
final class SiteSettings extends GlobalSet
{
    public function fields(): array
    {
        return [
            Text::make('site_name'),
        ];
    }
}
