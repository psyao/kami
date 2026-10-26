# Kami sets a strict, nonce-free CSP on the front; the admin gets Filament's minimum

None of the ten CMSs of the comparatif ships a CSP by default. In Velox the CSP was a documented recipe: aguet found no route to attach it to (friction A8) and ended with `'unsafe-inline'` and `'unsafe-eval'` (retrospective §2.7). A strict policy can only be written by whoever produces the HTML. Kami knows what its front loads: one static JS file, video iframes from two hosts, local Conversions. So Kami owns the headers of the pages it renders. A `SecurityHeaders` middleware (alias `kami.security-headers`) is always on in the `kami` route group, and the page cache stores and replays its headers (ADR 0021). The package registers nothing global (ADR 0001). The starter kit appends the middleware to the `web` group in its `bootstrap/app.php`, so a new site's own pages get the same policy. A host application adds that line by hand, and `kami:doctor` reports it when it has not.

The front policy is fixed, with no nonce and no `'unsafe-*'`:

```
default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:;
font-src 'self'; connect-src 'self'; media-src 'self';
frame-src https://www.youtube-nocookie.com https://player.vimeo.com;
object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none';
upgrade-insecure-requests
```

No nonce means a cached page stays a static file (ADR 0009). A site extends the policy through an additive array in `config/kami.php`. The array is merged with the defaults and never replaces them, and it applies to the whole installation, not per route. A route that needs Livewire or Alpine forces the site to add `'unsafe-eval'` everywhere, and `kami:doctor` reports it. That trades flexibility for one policy that a reader can check in one place (principle 1 over principle 6).

The admin cannot be held to that policy. Filament 5.10 with Livewire 4 needs `'unsafe-eval'` (Alpine and Livewire build functions from attributes; Livewire's `csp_safe` build does not parse Filament's templates yet). It also needs `style-src 'unsafe-inline'` (inline `<style>` blocks and `style` attributes, with no plan to change). The `admin` profile of the same middleware keeps what still protects: inline scripts need a per-request nonce (`Vite::useCspNonce()`, which Filament emits on its inline scripts), and `connect-src`, `img-src` and `frame-ancestors` are locked so injected markup cannot exfiltrate or frame the admin:

```
default-src 'self'; script-src 'self' 'nonce-…' 'unsafe-eval'; style-src 'self' 'unsafe-inline';
img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; worker-src 'self' blob:;
object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'
```

The starter kit's `AdminPanelProvider` adds it through `->middleware()`, because a panel does not run the `web` group. `kami:doctor` warns when the panel carrying `KamiPlugin` lacks it. The profile tightens when Livewire's CSP-safe mode works with Filament.

## Considered options

- **spatie/laravel-csp** (the comparatif's brick; MIT, maintained). Its value is policy presets and a per-request nonce. The front wants no nonce, and its `Basic` preset nonces `style-src`, which makes browsers ignore the `'unsafe-inline'` the admin needs. What is left is a middleware of a few dozen lines. Rejected under principle 8.
- **A nonce on the front.** It would allow inline scripts in site views, but every cached page would then need its nonce rewritten on each hit, so the reader running before boot (ADR 0021) would no longer serve a static file.
- **A policy per Content Type or per route.** It has no concrete need: aguet drops Alpine in its migration. A per-response hook is a door if one appears.
- **A Kami violation-report endpoint** (`/kami/csp-report`). Reports carry URLs and IP addresses, so they would need a table, retention and pruning (principle 2). Kami knows its own assets and enforces the policy from day one. `report_uri` and `report_only` exist as options, off by default.
- **HSTS and an HTTPS redirect sent by Kami.** That is transport, which belongs to the host. Infomaniak already sends `Strict-Transport-Security` once a certificate is active, so a second header would duplicate it. `kami:doctor` warns when production is served over HTTP.

## Consequences

- Other front headers: `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin` (Preview links `no-referrer`, ADR 0019), `Permissions-Policy: camera=(), microphone=(), geolocation=(), browsing-topics=()`, `Cross-Origin-Opener-Policy: same-origin`, `X-Frame-Options: DENY`, and `X-Robots-Tag` when the install is not Indexable (ADR 0027). There is no `X-XSS-Protection` and no `Cross-Origin-Resource-Policy`. `X-Powered-By` is removed, by the middleware and by the cache reader. The admin profile adds `nosniff`, `Referrer-Policy: same-origin` and `X-Frame-Options: DENY`.
- Headers apply to every response of the group, 4xx and 5xx included. The admin Preview and Preview links render front views, so they take the front profile.
- When `Vite::isRunningHot()` is true, the Vite dev server's origin (scripts, styles, HMR websocket) is added automatically. A `public/hot` file in production is a `kami:doctor` error.
- Files Apache serves without PHP get headers from `.htaccess` files that Kami writes: `public/media/.htaccess` adds `nosniff` next to the SVG policy (ADR 0017), and `kami:install` writes `public/vendor/kami/.htaccess` with `nosniff`. Conversions carry no CORP, so feed readers and sites linking to a page still show its images. On nginx, `kami:doctor` checks for these headers.
- The starter kit's `config/session.php` makes the admin cookie `secure` by default in production. `kami:doctor` warns when it is not.
- Every option that weakens security is off by default, lives under `kami.security.*`, is listed in `docs/security.md` and raises a `kami:doctor` warning when on: `'unsafe-*'` sources, `report_only`, a header set to `null` (omitted). There is no global switch that turns the headers off.
- Shared hosting and server behave the same. Only the `.htaccess` headers need reproducing on nginx.
