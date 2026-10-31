<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Fields;

/** Links to Terms of the Taxonomies named with `->of()`, read as a Collection in pick order, creatable from the picker. Shared by default. */
final class Terms extends RelationField
{
    /** @internal */
    #[\Override]
    protected bool $creatable = true;
}
