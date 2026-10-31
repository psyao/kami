<?php

declare(strict_types=1);

namespace Psyao\Kami\Content;

use Override;
use Psyao\Kami\Sites\Internal\Site;

/** A Singleton with no URL and always published, holding values shown across the Site, such as footer details. */
abstract class GlobalSet extends Singleton
{
    /** The Entry whatever its visibility; `null` until `kami:install` or site code creates it. */
    #[Override]
    public static function entry(): ?Entry
    {
        return Entry::query()->withUnpublished()->ofType(static::class)->where('site', Site::current()->handle)->first();
    }

    /** @internal */
    #[Override]
    final public function isRoutable(): bool
    {
        return false;
    }
}
