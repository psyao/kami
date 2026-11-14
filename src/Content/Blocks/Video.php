<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Blocks;

use Closure;
use Illuminate\Support\Str;
use Psyao\Kami\Content\Fields\Text;
use Psyao\Kami\Content\Fields\Url;

/**
 * A YouTube or Vimeo video, with an optional caption, that loads nothing from the provider before the visitor clicks (ADR 0016).
 *
 * `kami::blocks.video` renders a link to the provider, which Kami's facade script turns into its player on a click.
 * Opt-in: list it in `kami.blocks` and offer it with `->blocks()`.
 */
final class Video extends Block
{
    /** The facade script, under `public/`, which `kami:install` copies there: the path a view asks for with `asset()`. */
    public const string SCRIPT = 'vendor/kami/video.js';

    public function handle(): string
    {
        return 'video';
    }

    public function fields(): array
    {
        return [
            Url::make('url')->required()->rules([function (string $attribute, mixed $value, Closure $fail): void {
                // From the address alone: no network call on save. Something that is no address gets the url rule's message only.
                if (is_string($value) && Str::isUrl($value, ['http', 'https']) && ! VideoLink::of($value) instanceof VideoLink) {
                    $fail(__('kami::kami-blocks.video.invalid'));
                }
            }]),
            Text::make('caption'),
        ];
    }

    public function view(): string
    {
        return 'kami::blocks.video';
    }

    public function summary(array $data): ?string
    {
        return is_string($data['url'] ?? null) ? $data['url'] : null;
    }
}
