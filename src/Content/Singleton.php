<?php

declare(strict_types=1);

namespace Psyao\Kami\Content;

use Override;
use Psyao\Kami\Sites\Internal\Site;

/** A Content Type with exactly one Entry per Site, whose Path is its Prefix, such as a home page. */
abstract class Singleton extends ContentType
{
    /** The Entry when visible in the current Locale; `null` otherwise. */
    public static function entry(): ?Entry
    {
        return Entry::query()->ofType(static::class)->where('site', Site::current()->handle)->first();
    }

    /** @internal */
    #[Override]
    public function hasSlug(): bool
    {
        return false;
    }
}
