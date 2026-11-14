<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Blocks;

use Closure;
use Psyao\Kami\Content\Fields\Text;
use Psyao\Kami\Content\Fields\Url;
use Psyao\Kami\Content\Internal\VideoLink;

/**
 * A YouTube or Vimeo video from its address, with an optional caption, rendered by `kami::blocks.video` as a link to the provider
 * that Kami's facade script turns into its player on a click: no request reaches the provider before it (ADR 0016).
 * Opt-in: list it in `kami.blocks` and offer it with `->blocks()`.
 */
final class Video extends Block
{
    public function handle(): string
    {
        return 'video';
    }

    public function fields(): array
    {
        return [
            Url::make('url')->required()->rules([function (string $attribute, mixed $value, Closure $fail): void {
                // From the address alone: no network call on save.
                if (! VideoLink::of($value) instanceof VideoLink) {
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
