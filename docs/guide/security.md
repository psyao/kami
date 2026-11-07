# Security

Every page Kami renders carries a strict Content Security Policy and a few companion headers, so that injected markup can neither run a script nor frame the page. Kami's admin gets a policy of its own, as strict as Filament allows. Both come from one middleware, `Psyao\Kami\Support\SecurityHeaders`.

## Kami's pages

Kami's route runs the middleware on every response, 404 and 500 included, with no setting to turn it off. Its policy is fixed:

```
default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:;
font-src 'self'; connect-src 'self'; media-src 'self';
frame-src https://www.youtube-nocookie.com https://player.vimeo.com;
object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none';
upgrade-insecure-requests
```

Scripts, styles, fonts and images load from your own site only, as files: an inline `<script>`, a `style` attribute or an `onclick` is blocked. Build your front with Vite and link its files, as the starter kit's layout does. There is no nonce, so a page can be cached as a static file.

The companion headers:

| Header | Value |
| --- | --- |
| `X-Content-Type-Options` | `nosniff` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=(), browsing-topics=()` |
| `Cross-Origin-Opener-Policy` | `same-origin` |
| `X-Frame-Options` | `DENY` |

Images and files under `public/media`, which Apache serves without PHP, get `nosniff` and, for an SVG, a policy that runs no script and loads nothing, from Kami's `.htaccess` ([Hosting](hosting.md#conversions)). On nginx, add them yourself ([nginx](hosting.md#nginx)).

`X-Powered-By` is removed. Kami sends no `Strict-Transport-Security`: HTTPS is your host's, and Infomaniak sends it once a certificate is active.

## Your own pages

Your application's routes run in the `web` group, not Kami's. Append the middleware to it in `bootstrap/app.php`, so they get the same policy; the starter kit does:

```php
use Psyao\Kami\Support\SecurityHeaders;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->web(append: [SecurityHeaders::class]);
})
```

`kami:doctor` warns while the `web` group lacks it.

## The admin

A Filament panel does not run the `web` group. Add the middleware's `admin` profile to your panel provider's middleware:

```php
->middleware([
    // Filament's own middleware…
    'kami.security-headers:admin',
])
```

Filament needs `'unsafe-eval'` for its scripts and inline styles, so the admin policy keeps what still protects: inline scripts need a nonce drawn for each request, which Filament and Livewire add to theirs through Laravel's `Vite::useCspNonce()`, and images, connections and framing stay locked to your site.

```
default-src 'self'; script-src 'self' 'nonce-…' 'unsafe-eval'; style-src 'self' 'unsafe-inline';
img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; worker-src 'self' blob:;
object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'
```

Its companion headers are `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin` and `X-Frame-Options: DENY`. The admin policy is fixed: the options below change the front only. `kami:doctor` warns when a panel carrying `KamiPlugin` lacks the middleware.

## Password reset

Enable reset on your panel with `->passwordReset()`, as the starter kit does. `KamiPlugin` then serves its own request page in place of Filament's, whatever the order of the two calls. Filament's page answers an unknown address with "We can't find a user with that email address", which tells a stranger who has an account. Kami's answers every address the same way: known, unknown, or asked for a second link within a minute (`Password::RESET_THROTTLED`), the page says "Check your email" and that a link went out if an account uses the address.

The timing says nothing either. The page answers first; the password broker looks the address up and sends the email after the response, through Laravel's `defer()`. Under PHP-FPM, which Infomaniak runs, PHP finishes the request before the deferred work starts. Under Apache's `mod_php` there is no such finish: the email goes out before the response, and a known address answers more slowly.

The email is sent then and there, not queued as Filament's notification asks, since a shared host runs no queue worker ([Hosting](hosting.md#what-kami-does-not-need)). Filament's limit of two requests per minute from one IP address stays, with its own message: it says nothing about the address typed.

The link opens Filament's own page to choose the new password. A completed reset lands in the [Audit log](audit-log.md) as `kami.auth.password-reset`.

A class of your own, `->passwordReset(MyRequestPage::class)`, wins over Kami's. Keeping it from revealing who has an account is then yours, and `kami:doctor` warns.

## MFA

Users who may manage Users must sign in with an authenticator app's code after their password; others may choose to ([Users and roles](users-and-roles.md#mfa)). A stolen password alone then hands out no rights.

The secrets are encrypted with `APP_KEY`. Rotating it breaks every authenticator unless the old key stays readable: list it in `APP_PREVIOUS_KEYS` (comma-separated) when you set a new `APP_KEY`, as Laravel's encryption reads both. A User locked out anyway, by a lost phone and lost recovery codes or a key gone for good, sets MFA up again after `php artisan kami:user:reset-mfa` with its email ([From the command line](users-and-roles.md#from-the-command-line)).

`kami:doctor` makes sure the rule is not skipped by accident. It reports an error when a panel carrying `KamiPlugin` no longer requires Kami's provider, because a `->multiFactorAuthentication()` or `->requiresMultiFactorAuthentication()` set after the plugin replaced it, and when the panel's User model does not implement Filament's `HasAppAuthentication` and `HasAppAuthenticationRecovery`. It warns with the number of Users who may manage Users and have not set MFA up yet.

## HTTPS in production

The admin's session cookie and every password typed into the panel travel with each request: production is served over HTTPS only. HSTS stays your host's to send; add it under `security.headers` if it does not.

`kami:doctor` warns, in production only, when:

- `APP_URL` or a Site's `url` in `config/kami.php` starts with `http://`: serve the site over HTTPS, then change the address. Infomaniak serves HTTPS once the site's certificate is active.
- The session cookie is not always `Secure`, because `session.secure` is not `true`. Left unset, Laravel adds `Secure` only to a request it sees as HTTPS, and behind a shared host's proxy, which Laravel does not trust by default, it sees plain HTTP: the cookie goes out without the flag. Set `SESSION_SECURE_COOKIE=true` in `.env`; the starter kit's `config/session.php` defaults it to `true` in production.

## Extending the policy

A front that loads from another origin, such as images from a CDN, adds it under `security.csp` in `config/kami.php`, per directive:

```php
'security' => [
    'csp' => [
        'img-src' => ['https://cdn.example.com'],
    ],
],
```

The sources are added to Kami's and never replace them: `img-src` becomes `'self' data: https://cdn.example.com`. A directive Kami does not set, such as `manifest-src`, is added. A single source may be given as a string. The policy is the same for every page of the installation.

An inline `<script>` or `<style>` you cannot move to a file is allowed by its hash, as `'sha256-…'` in `script-src` or `style-src`, rather than by `'unsafe-inline'`.

## Weakening options

Each option below is off by default, and `kami:doctor` warns for each one that is on:

| Option | Effect |
| --- | --- |
| `security.csp` with an `'unsafe-*'` source (`'unsafe-inline'`, `'unsafe-eval'`, `'unsafe-hashes'`) | Injected markup can run code in that directive. Livewire and Alpine need `'unsafe-eval'` unless your templates fit Livewire's `csp_safe` build. |
| `security.csp` with a source in a directive Kami sets to `'none'` (`object-src`, `base-uri`, `frame-ancestors`) | The source replaces `'none'`: plugins, a changed base URL or framing by that origin are allowed. |
| `security.headers` with a header set to `null` | The header is omitted. Names are case-insensitive, as in HTTP. |
| `security.headers` with another value for one of Kami's headers | Kami's value is replaced. A header Kami does not send, such as `Strict-Transport-Security`, is added and raises no warning. |
| `security.csp_report_uri` | Browsers send each violation, with the page's URL and the visitor's IP address, to that address. |
| `security.csp_report_only` | The policy is sent as `Content-Security-Policy-Report-Only`: violations are reported, not blocked. |

There is no switch that turns the headers off.

Shared hosting and a server behave the same: the headers are sent by PHP on every page Kami or your application renders.

## Vite in development

While `npm run dev` runs, Vite writes `public/hot`, and both profiles add its dev server's origin to `script-src`, `style-src`, `img-src`, `font-src` and `connect-src`, with its websocket for hot reloading. Nothing to configure.

That file must never reach production: pages would load their scripts from a dev server, and the policy would allow it. `kami:doctor` fails when it finds `public/hot` in production.

## Local development over HTTP

The front policy sends `upgrade-insecure-requests` in every environment. On a local site served over plain `http`, the browser therefore requests `/build/*` over HTTPS, the certificate check fails (for example `ERR_CERT_COMMON_NAME_INVALID`) and the page renders unstyled.

Serve the site over HTTPS, with an `https` `APP_URL`. On Herd, from the site's directory:

```bash
herd secure
```

The console's `Cross-Origin-Opener-Policy` message about an untrustworthy origin goes away over HTTPS too.
