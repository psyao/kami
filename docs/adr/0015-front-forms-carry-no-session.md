# Front forms carry no session: an origin check, a honeypot and throttling replace the CSRF token, and errors re-render the origin page

Kami's public routes run outside Laravel's `web` group, so they have no session, no CSRF token and no cookie, and a page that sets a cookie is never page-cached (ADR 0021). A Form sits on a page that is otherwise cacheable. On aguet.dev that page is the Home page, which is the whole site. So a Form adds nothing to the page that is tied to a session. Submissions post to `POST /kami/forms/{handle}`, registered by `Kami::routes()` in the `kami` group. `<x-kami::form>` emits the `<form>` tag, a honeypot field and `_kami_origin`, which holds the origin Entry's ULID and Locale. That field is not a secret. The route protects itself in three ways:

- **Origin check.** A request is accepted when `Sec-Fetch-Site` is `same-origin`. When that header is absent, it is accepted if `Origin` is absent or matches the Site's host. Otherwise it gets a 403. This follows `PreventRequestForgery::hasValidOrigin()` from Laravel 13, with the `Origin` fallback added. It is applied to this route only, because that middleware's configuration is static and global. Browsers do not let scripts set these headers, so another site cannot make its visitors' browsers post to the Form. That would be the only thing a CSRF token could prevent here, since the front has no ambient authority to abuse.
- **Honeypot.** When the hidden field is filled, the response is the normal success response, and nothing is stored.
- **Per-IP throttling.** Five submissions per 60 seconds per Form. IPv6 addresses are grouped by /64. The key is hashed, in the application cache (the `file` store on shared hosting). Above the limit the response is a 429. A submission counts against the limit only once it has passed every other check, so a bot caught by the honeypot never uses up the quota of a real visitor behind the same IP (a practice from kawaiidream.net). The IP is never stored with the Submission. When a host mounts Kami's routes with its session (ADR 0038), `x-kami::form` also emits `@csrf`.

A direct bot forges any header it likes, but it posts from its own IP. The honeypot and the throttle are aimed at it. A CSRF token would not stop it either, because a bot can GET the page and read the token.

Without a session there is no "redirect back with errors". When validation fails, the controller resolves the origin Entry, which must be visible on the request's Site. It sets the Locale from `_kami_origin`, shares `$errors` and the old input with the views, and renders the Entry through `ContentType::render()` (ADR 0009). The response is a 422 with `Cache-Control: no-store`. On success it answers `303` to the Entry's URL with `?sent={handle}#{handle}`, and the view reads `Kami::form($handle)->sent()`. A URL with a query string is never page-cached (ADR 0021). A missing or invalid origin gets a 422 rendered by the 4xx error page, and nothing is stored.

The decision puts privacy (principle 2) and the page cache (principle 7) above the Laravel-native idiom (principle 8). Forms get no CSRF token, no flashed errors and no session-backed old input. Kami drops aguet's 2-second minimum fill time: a timestamp in a cached page records when the page was cached, not when the visitor saw it.

## Considered options

- **A session on pages that hold a Form**, through `Kami::withoutPageCache()` and a per-Content-Type middleware declaration. aguet's only page would lose the page cache: about 170 ms of server time instead of 30 ms or less, with OPcache off and a remote database (ADR 0021). Every visitor would also get a cookie before doing anything. The middleware would have to know, before rendering, whether a Form is on the page. A site that wants this can still write its own `web` route and call `Kami::withoutPageCache()`.
- **A session opened only on the POST**, so that errors can use the redirect pattern. It needs a session store. It leaves a cookie on the visitor, and that cookie makes every later page bypass the cache. The only gain is that reloading after an error does not ask to resend.
- **`spatie/laravel-responsecache` with its CSRF token replacer.** It needs a session on every visit and a full framework boot on every hit. ADR 0021 rejected it.
- **A captcha (Turnstile, reCAPTCHA).** It is a remote service, so it fails data sovereignty and privacy (principles 2-3). A site can add a self-hosted proof-of-work rule through `rules()`, with no Kami hook.
- **Livewire, as aguet uses today.** The brief keeps Livewire off the public front, and ADR 0009 ships none.

## Consequences

- `x-kami::form` makes no per-request change to the page, so a page that holds a Form is cached like any other.
- HTML constraints (`required`, `maxlength`, `type="email"`) in the site's markup catch most errors before the POST, so the 422 re-render is rare.
- `<x-kami::form.errors>` renders a focusable error summary with `role="alert"` and links to the fields. The site writes the rest of the markup.
- A JSON response for `fetch()` submissions is a V1 door on the same route.
