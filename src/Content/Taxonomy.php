<?php

declare(strict_types=1);

namespace Psyao\Kami\Content;

use Override;

/** A Content Type classifying others: its Entries are `Term`s, published by default, linked with the `Terms` Field (ADR 0002). */
abstract class Taxonomy extends ContentType
{
    #[Override]
    protected ?string $model = Term::class;
}
