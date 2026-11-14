<?php

declare(strict_types=1);

namespace Psyao\Kami\Forms\Actions;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Forms\Form;
use Psyao\Kami\Forms\Submission;
use Psyao\Kami\Kami;
use Psyao\Kami\Sites\Internal\Site;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A visitor's Submission, checked as the Form route checks it, in this order: the honeypot, the page, the per-IP throttle, then the
 * Form's `rules()`; it stores through `StoreSubmission`. For a site's own Livewire action or controller: the visitor's origin
 * (`Sec-Fetch-Site`) stays the caller's to check, as Livewire's update route and Laravel's CSRF check already do (ADR 0040).
 */
final class SubmitForm
{
    /** The honeypot's name: a field a person never sees, which a bot fills. */
    public const string HONEYPOT = 'kami_website';

    private const int MAX_ATTEMPTS = 5;

    private const int DECAY_SECONDS = 60;

    /**
     * @param  Form|string  $form  The declared Form, or its handle.
     * @param  array<mixed>  $input  The visitor's values, the honeypot's included; any key `rules()` does not name is not stored.
     * @param  Entry|null  $entry  The page it was sent from.
     * @param  string  $locale  A Locale the page is visible in, which the request then uses.
     * @return Submission|null The stored Submission, or null when the honeypot caught a bot: answer it the normal success.
     *
     * @throws HttpException 422 when the page is not visible in that Locale; 429, with `Retry-After`, above five per minute per Form and per IP.
     * @throws ValidationException
     */
    public function handle(Form | string $form, array $input, ?Entry $entry, string $locale): ?Submission
    {
        $form = is_string($form) ? resolve(Kami::class)->form($form) : $form;

        // The normal success before the page and the throttle are checked, nothing stored and nothing counted: a bot learns nothing, and a person behind its IP keeps their quota.
        if (filled($input[self::HONEYPOT] ?? null)) {
            return null;
        }

        abort_unless(in_array($locale, Site::current()->locales, true), 422);
        Site::useLocale($locale);
        app()->setLocale($locale);
        abort_if($entry?->url($locale) === null, 422);

        $key = $this->throttleKey((string) request()->ip(), $form);

        // Counted first, so parallel requests cannot all pass a check made before any of them counts.
        if (RateLimiter::hit($key, self::DECAY_SECONDS) > self::MAX_ATTEMPTS) {
            abort(429, headers: ['Retry-After' => RateLimiter::availableIn($key)]);
        }

        $validator = Validator::make(array_diff_key($input, [self::HONEYPOT => true]), $form->rules());

        return resolve(StoreSubmission::class)->handle($form, $validator->validate(), $locale, $entry);
    }

    /** Per Form and per IP, an IPv6 address grouped by its /64, hashed so the cache holds no address. */
    private function throttleKey(string $ip, Form $form): string
    {
        $packed = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false ? false : inet_pton($ip);
        // An IPv4 address a dual-stack listener reports as `::ffff:a.b.c.d` counts as itself, not with every IPv4 visitor in `::/64`.
        $group = match (true) {
            $packed === false => $ip,
            str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff") => (string) inet_ntop(substr($packed, 12)),
            default => bin2hex(substr($packed, 0, 8)),
        };

        return 'kami:form:' . hash('sha256', "{$form->handle()}|{$group}");
    }
}
