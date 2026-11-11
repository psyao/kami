<?php

declare(strict_types=1);

namespace Psyao\Kami\Forms\Internal;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Uri;
use Illuminate\Support\ViewErrorBag;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Forms\Actions\StoreSubmission;
use Psyao\Kami\Forms\Form;
use Psyao\Kami\Routing\Internal\RenderEntry;
use Psyao\Kami\Sites\Internal\Site;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /kami/forms/{handle}`: no session, cookie or CSRF token, so the page holding the Form stays cached (ADR 0015).
 * Checks the origin, the honeypot, `_kami_origin` and the throttle, then validates: errors re-render the page as a 422, success answers 303.
 *
 * @internal
 */
final class SubmitForm
{
    /** The honeypot's name: a field a person never sees, which a bot fills. */
    public const string HONEYPOT = 'kami_website';

    public const string ORIGIN = '_kami_origin';

    private const int MAX_ATTEMPTS = 5;

    private const int DECAY_SECONDS = 60;

    /** Never sent back to the page, as Laravel's handler never flashes them. */
    private const array NEVER_OLD = ['password', 'password_confirmation', 'current_password'];

    public function __invoke(Request $request, string $handle): Response
    {
        $form = resolve(Forms::class)->find($handle) ?? abort(404);

        abort_unless($this->sameOrigin($request), 403);

        $origin = $this->origin($request);
        $sent = fn (?string $url): RedirectResponse => redirect()->away("{$url}?sent={$form->handle()}#{$form->handle()}", 303);

        // The normal success before the page and the throttle are checked, nothing stored and nothing counted: a bot learns nothing, and a person behind its IP keeps their quota.
        if ($request->filled(self::HONEYPOT)) {
            return $sent($origin === null ? Site::current()->url : $origin[0]->url($origin[1]));
        }

        [$entry, $locale] = $origin ?? abort(422);
        $key = $this->throttleKey($request, $form);

        // Counted first, so parallel requests cannot all pass a check made before any of them counts.
        if (RateLimiter::hit($key, self::DECAY_SECONDS) > self::MAX_ATTEMPTS) {
            abort(429, headers: ['Retry-After' => RateLimiter::availableIn($key)]);
        }

        $input = $request->except([self::HONEYPOT, self::ORIGIN]);
        $validator = Validator::make($input, $form->rules());

        if ($validator->fails()) {
            return $this->invalid($request, $entry, $validator->errors(), Arr::except($input, self::NEVER_OLD));
        }

        resolve(StoreSubmission::class)->handle($form, $validator->validated(), $locale, $entry);

        return $sent($entry->url($locale));
    }

    /** `Sec-Fetch-Site` from a browser that sends it; otherwise an `Origin` that is absent or the Site's (ADR 0015). Scripts can set neither. */
    private function sameOrigin(Request $request): bool
    {
        if ($request->headers->has('Sec-Fetch-Site')) {
            return $request->headers->get('Sec-Fetch-Site') === 'same-origin';
        }

        $origin = $request->headers->get('Origin');
        $site = Uri::of(Site::current()->url);
        // As a browser serialises it: a lowercase host, and no port when it is the scheme's default.
        $port = in_array([$site->scheme(), $site->port()], [['http', 80], ['https', 443]], true) ? null : $site->port();

        return $origin === null || $origin === strtolower("{$site->scheme()}://{$site->host()}") . ($port === null ? '' : ":{$port}");
    }

    /**
     * The visible Entry and Locale `_kami_origin` names, read in that Locale from here on.
     *
     * @return array{Entry, string}|null
     */
    private function origin(Request $request): ?array
    {
        // Read raw: `string()` would cast an array a bot posts and throw.
        $origin = $request->input(self::ORIGIN);
        [$id, $locale] = explode(':', is_string($origin) ? $origin : '', 2) + [1 => ''];

        if (! in_array($locale, Site::current()->locales, true)) {
            return null;
        }

        Site::useLocale($locale);
        app()->setLocale($locale);
        $entry = Entry::query()->whereKey($id)->first();

        return $entry?->url($locale) === null ? null : [$entry, $locale];
    }

    /** Per Form and per IP, an IPv6 address grouped by its /64, hashed so the cache holds no address. */
    private function throttleKey(Request $request, Form $form): string
    {
        $ip = (string) $request->ip();
        $packed = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false ? false : inet_pton($ip);
        // An IPv4 address a dual-stack listener reports as `::ffff:a.b.c.d` counts as itself, not with every IPv4 visitor in `::/64`.
        $group = match (true) {
            $packed === false => $ip,
            str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff") => (string) inet_ntop(substr($packed, 12)),
            default => bin2hex(substr($packed, 0, 8)),
        };

        return 'kami:form:' . hash('sha256', "{$form->handle()}|{$group}");
    }

    /** The page again with the errors and the visitor's input, for this response only: a store held in memory, so no cookie, under the `web` group the real session. */
    /** @param  array<mixed>  $old */
    private function invalid(Request $request, Entry $entry, MessageBag $errors, array $old): Response
    {
        if (! $request->hasSession()) {
            $request->setLaravelSession(new Store('kami', new ArraySessionHandler(0)));
        }

        $request->session()->now('_old_input', $old);
        $bag = new ViewErrorBag()->put('default', $errors);

        $response = Router::toResponse($request, RenderEntry::render($request, $entry, $bag));
        // A page answered 200; a type whose `render()` chose another status, a redirect for one, keeps it.
        if ($response->getStatusCode() === 200) {
            $response->setStatusCode(422);
        }
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
