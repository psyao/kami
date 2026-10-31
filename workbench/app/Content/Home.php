<?php

declare(strict_types=1);

namespace Workbench\App\Content;

use Psyao\Kami\Content\Fields\Textarea;
use Psyao\Kami\Content\Singleton;

/** A Singleton at the root: `/` and `/en`. */
final class Home extends Singleton
{
    #[\Override]
    protected ?string $prefix = '';

    public function fields(): array
    {
        return [
            Textarea::make('intro'),
        ];
    }
}
