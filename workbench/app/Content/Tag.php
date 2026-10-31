<?php

declare(strict_types=1);

namespace Workbench\App\Content;

use Psyao\Kami\Content\Taxonomy;

/** One name for every Locale, no address of its own: what classifies a Project. */
final class Tag extends Taxonomy
{
    #[\Override]
    protected bool $translatable = false;

    #[\Override]
    protected bool $routable = false;
}
