<?php

declare(strict_types=1);

namespace Psyao\Kami\Support\Internal;

/**
 * ADR 0018's fixed policies and companion headers, read by `SecurityHeaders` and by the doctor.
 *
 * @internal
 */
final class SecurityPolicy
{
    /** @var array<string, list<string>> */
    public const array FRONT = [
        'default-src' => ["'self'"],
        'script-src' => ["'self'"],
        'style-src' => ["'self'"],
        'img-src' => ["'self'", 'data:'],
        'font-src' => ["'self'"],
        'connect-src' => ["'self'"],
        'media-src' => ["'self'"],
        'frame-src' => ['https://www.youtube-nocookie.com', 'https://player.vimeo.com'],
        'object-src' => ["'none'"],
        'base-uri' => ["'none'"],
        'form-action' => ["'self'"],
        'frame-ancestors' => ["'none'"],
        'upgrade-insecure-requests' => [],
    ];

    /** @var array<string, string> */
    public const array FRONT_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), browsing-topics=()',
        'Cross-Origin-Opener-Policy' => 'same-origin',
        'X-Frame-Options' => 'DENY',
    ];

    /**
     * Filament 5 with Livewire 4 needs `'unsafe-eval'` and inline styles; the nonce joins `script-src` after `'self'`.
     *
     * @var array<string, list<string>>
     */
    public const array ADMIN = [
        'default-src' => ["'self'"],
        'script-src' => ["'self'", "'unsafe-eval'"],
        'style-src' => ["'self'", "'unsafe-inline'"],
        'img-src' => ["'self'", 'data:', 'blob:'],
        'font-src' => ["'self'"],
        'connect-src' => ["'self'"],
        'worker-src' => ["'self'", 'blob:'],
        'object-src' => ["'none'"],
        'base-uri' => ["'none'"],
        'form-action' => ["'self'"],
        'frame-ancestors' => ["'none'"],
    ];

    /** @var array<string, string> */
    public const array ADMIN_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'same-origin',
        'X-Frame-Options' => 'DENY',
    ];

    /** The directives that take the Vite dev server's origin while it runs. */
    public const array HOT_DIRECTIVES = ['script-src', 'style-src', 'img-src', 'font-src', 'connect-src'];
}
