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

`X-Powered-By` is removed. Kami sends no `Strict-Transport-Security`: HTTPS is your host's, and Infomaniak sends it once a certificate is active.

## Your own pages

Your application's routes run in the `web` group, not Kami's. Append the middleware to it in `bootstrap/app.php`, so they get the same policy:

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
