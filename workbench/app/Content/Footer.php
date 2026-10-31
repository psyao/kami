<?php

declare(strict_types=1);

namespace Workbench\App\Content;

use Psyao\Kami\Content\Fields\Email;
use Psyao\Kami\Content\Fields\Text;
use Psyao\Kami\Content\GlobalSet;

/** Values shown on every page, read with `Footer::entry()`. */
final class Footer extends GlobalSet
{
    public function fields(): array
    {
        return [
            Text::make('tagline'),
            Email::make('contact'),
        ];
    }
}
