<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Blocks;

/**
 * A `Video` Block's address, read by its form alone, with no network call: what an overridden `kami::blocks.video` renders.
 *
 * It reads YouTube's watch, short and Shorts addresses and public Vimeo ones, as Velox's `VideoUrl` did.
 */
final readonly class VideoLink
{
    private const string YOUTUBE = '~^https?://(?:(?:www\.|m\.)?youtube\.com/(?:watch\?(?:[^#]*?&)??v=|shorts/)|youtu\.be/)([\w-]{11})(?:[?&#/].*)?\z~';

    private const string VIMEO = '~^https?://(?:www\.)?vimeo\.com/(\d+)/?(?:[?#].*)?\z~';

    /**
     * @param  string  $provider  `YouTube` or `Vimeo`.
     * @param  string  $watch  The provider's own page, linked without JS.
     * @param  string  $player  The privacy-preserving player the facade loads on a click: `youtube-nocookie.com`, or `player.vimeo.com` with `dnt=1`.
     */
    private function __construct(public string $provider, public string $watch, public string $player) {}

    /** The video an address names, or null for anything but a YouTube or Vimeo address. */
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
