<?php

declare(strict_types=1);

namespace Workbench\App\Content;

use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Fields\Textarea;

/** A type with no URL, listed on other pages. */
final class Testimonial extends ContentType
{
    #[\Override]
    protected bool $routable = false;

    public function fields(): array
    {
        return [
            Textarea::make('quote'),
        ];
    }
}
