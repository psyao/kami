<?php

declare(strict_types=1);

namespace Psyao\Kami\Media\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Psyao\Kami\Media\Media;

/**
 * A Media item was saved through `SaveMedia`, once its transaction committed (ADR 0029).
 */
final readonly class MediaSaved implements ShouldDispatchAfterCommit
{
    /**
     * @param  Media  $media  The Media item as saved, its Translations loaded.
     * @param  string  $locale  The Locale its alt text and caption were written in.
     * @param  bool  $created  Whether this save created the Media item.
     * @param  Authenticatable|null  $actor  Null for imports and the CLI.
     */
    public function __construct(
        public Media $media,
        public string $locale,
        public bool $created,
        public ?Authenticatable $actor,
    ) {}
}
