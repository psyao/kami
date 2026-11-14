<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

/**
 * A `Video` Block's address, read without a network call: YouTube's watch, short and Shorts addresses, and public Vimeo ones,
 * as Velox's `VideoUrl` read them.
 *
 * @internal
 */
final readonly class VideoLink
{
    private const string YOUTUBE = '~^https?://(?:(?:www\.|m\.)?youtube\.com/(?:watch\?(?:[^#]*?&)??v=|shorts/)|youtu\.be/)([\w-]{11})(?:[?&#/].*)?\z~';

    private const string VIMEO = '~^https?://(?:www\.)?vimeo\.com/(\d+)/?(?:[?#].*)?\z~';

    /**
     * @param  string  $watch  The provider's own page, linked without JS.
     * @param  string  $player  The privacy-preserving player the facade loads on a click.
     */
    private function __construct(public string $provider, public string $watch, public string $player) {}

    public static function of(mixed $url): ?self
    {
        $url = is_string($url) ? trim($url) : '';

        return match (true) {
            preg_match(self::YOUTUBE, $url, $match) === 1 => new self('YouTube', "https://www.youtube.com/watch?v={$match[1]}", "https://www.youtube-nocookie.com/embed/{$match[1]}?autoplay=1"),
            preg_match(self::VIMEO, $url, $match) === 1 => new self('Vimeo', "https://vimeo.com/{$match[1]}", "https://player.vimeo.com/video/{$match[1]}?dnt=1&autoplay=1"),
            default => null,
        };
    }
}
