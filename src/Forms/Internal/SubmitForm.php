<?php

declare(strict_types=1);

namespace Psyao\Kami\Forms\Internal;

use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Arr;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Uri;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\ValidationRuleParser;
use Illuminate\Validation\Validator;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Forms\Actions\SubmitForm as Submit;
use Psyao\Kami\Routing\Internal\RenderEntry;
use Psyao\Kami\Sites\Internal\Site;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /kami/forms/{handle}`: no session, cookie or CSRF token, so the page holding the Form stays cached (ADR 0015).
 * Checks the origin, reads `_kami_origin`, and leaves the rest to the public `SubmitForm` action: errors re-render the page as a 422, success answers 303.
 *
 * @internal
 */
final class SubmitForm
{
    public const string ORIGIN = '_kami_origin';

    /** Never sent back to the page, as Laravel's handler never flashes them. */
    private const array NEVER_OLD = ['password', 'password_confirmation', 'current_password'];

    public function __invoke(Request $request, string $handle, Submit $submit): Response
    {
        $form = resolve(Forms::class)->find($handle) ?? abort(404);

        abort_unless($this->sameOrigin($request), 403);

        $origin = $this->origin($request);
        $input = $request->except(self::ORIGIN);

        try {
            $submit->handle($form, $input, $origin[0] ?? null, $origin[1] ?? '');
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                // The body Laravel's handler renders for a `ValidationException`, kept out of any cache.
                return response()->json(['message' => $exception->getMessage(), 'errors' => $exception->errors()], 422, ['Cache-Control' => 'no-store']);
            }

            $validator = $exception->validator;

            // Validation runs once the page is known, through Laravel's own validator unless a site replaced it.
            if (! $validator instanceof Validator || $origin === null) {
                throw $exception;
            }

            // Laravel's own parsed rules, one list per field with `*` expanded against the posted data, and the Form's unexpanded wildcard keys.
            return $this->invalid($request, $origin[0], $validator->errors(), Arr::except($input, [Submit::HONEYPOT, ...self::NEVER_OLD]), [...$form->rules(), ...$validator->getRules()]);
        }

        // A site's own script gets the Confirmation, in the Locale `_kami_origin` set; a browser goes back to the page, or to the Site's home for a bot with no known page.
        return $request->expectsJson()
            ? response()->json(['message' => $form->confirmation()], headers: ['Cache-Control' => 'no-store'])
            : redirect()->away(($origin === null ? Site::current()->url : $origin[0]->url($origin[1])) . "?sent={$form->handle()}#{$form->handle()}", 303);
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

    /**
     * The page again with the errors and the visitor's input, for this response only: a store held in memory, so no cookie, under the `web` group the real session.
     *
     * @param  array<mixed>  $old
     * @param  array<mixed>  $rules
     */
    private function invalid(Request $request, Entry $entry, MessageBag $errors, array $old, array $rules): Response
    {
        if (! $request->hasSession()) {
            $request->setLaravelSession(new Store('kami', new ArraySessionHandler(0)));
        }

        $request->session()->now('_old_input', self::shaped($old, $rules));
        $bag = new ViewErrorBag()->put('default', $errors);

        $response = Router::toResponse($request, RenderEntry::render($request, $entry, $bag));
        // A page answered 200; a type whose `render()` chose another status, a redirect for one, keeps it.
        if ($response->getStatusCode() === 200) {
            $response->setStatusCode(422);
        }
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * The old input in the shape its rules allow, so `{{ old('name') }}` never receives an array a bot posted as `name[]=x`, nor a
     * checkbox group's `in_array()` a string (#530). An array is kept where a rule says `array` or `list` at its path, or a rule reaches
     * below it (`topics.*`, `answer.text`), a wildcard matching any key: the unexpanded rules hold the wildcards the posted data did not
     * expand, such as `tags.*` for a scalar `tags`. A value of the other shape is dropped.
     *
     * @param  array<mixed>  $values
     * @param  array<mixed>  $rules  The parsed rules, by expanded key, and the Form's own wildcard keys.
     * @return array<mixed>
     */
    private static function shaped(array $values, array $rules, string $prefix = ''): array
    {
        foreach ($values as $key => $value) {
            $path = "{$prefix}{$key}";
            $segments = explode('.', $path);
            // A rule object left by the parser is a custom rule, never `array`.
            $array = array_any((array) ($rules[$path] ?? []), fn (mixed $rule): bool => is_string($rule) && in_array(ValidationRuleParser::parse($rule)[0], ['Array', 'List'], true))
                || array_any(array_keys($rules), function (int | string $key) use ($segments): bool {
                    $rule = explode('.', (string) $key);

                    return count($rule) > count($segments)
                        && array_all($segments, fn (string $segment, int $i): bool => in_array($rule[$i], ['*', $segment], true));
                });

            if (is_array($value) && $array) {
                $values[$key] = self::shaped($value, $rules, "{$path}.");
            } elseif (is_array($value) || $array) {
                unset($values[$key]);
            }
        }

        return $values;
    }
}
