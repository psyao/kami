<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Blocks;

use Psyao\Kami\Content\Fields\Media;
use Psyao\Kami\Content\Fields\Text;

/**
 * An image from the Media library with an optional caption, rendered as `<x-kami::image>` in a `<figure>` through `kami::blocks.image`.
 * Its alt text is the Media item's. Opt-in: list it in `kami.blocks` and offer it with `->blocks()`.
 */
final class Image extends Block
{
    public function handle(): string
    {
        return 'image';
    }

    public function fields(): array
    {
        return [
            Media::make('image')->images()->required(),
            Text::make('caption'),
        ];
    }

    public function view(): string
    {
        return 'kami::blocks.image';
    }

    public function summary(array $data): ?string
    {
        return is_string($data['caption'] ?? null) ? $data['caption'] : null;
    }
}
