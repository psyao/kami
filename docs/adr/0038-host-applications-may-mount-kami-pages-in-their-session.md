# Host applications may mount Kami pages in their session, and Kami never shadows a host route

Kami's public routes run outside `web`, with no session and no cookie, so the page cache can serve them (ADR 0021). An application with signed-in visitors breaks that model. On kawaiidream.net, the layout shows the member's menu (`@auth`), a logout form (`@csrf`), Livewire and Alpine. On a sessionless Kami page, a signed-in member would appear signed out. CVCI and Vaud Rando have member areas too.

So the host chooses per mount. `Kami::routes()` stays sessionless by default. `Kami::routes(session: true)` adds the `web` group to the Entry, Archive and Form routes, but never to the Tick or the Feeds. Those responses set the session cookie, so the page cache never stores them, by the rule already in ADR 0021, with no special case. `x-kami::form` emits `@csrf` whenever a session is started.

The decision trades performance (principle 7) for a consistent visitor experience. Session pages lose the page cache and fall back to the uncached budget (≤ 200 ms). The host's scripts also have to fit Kami's nonce-free front CSP. Livewire 4's `csp_safe` build, with Alpine's CSP build, needs no `'unsafe-eval'`, as long as the host's expressions stay within its subset: no arrow functions, template literals, spread or globals, and complex logic moved to `Alpine.data()`. Inline `<script>` and `<style>` blocks, such as `@livewireStyles` or a theme script, go into the additive CSP array of `config/kami.php` as `sha256` hashes. Otherwise the host adds `'unsafe-eval'` or `'unsafe-inline'`, which `kami:doctor` reports as a weakening (ADR 0018). That CSP is no weaker than what the host already accepts on its own pages.

## Considered options

- **Sessionless pages only, with a "guest" layout written by the host.** A signed-in member sees the header change on every editorial page.
- **Serve the page cache to guests and bypass it when a session cookie is present** (the WordPress approach). Laravel's `web` group gives every visitor a session cookie on their first request, so almost nobody would get a cached page. The cost is a second code path in the reader for almost no gain.
- **The host wraps `Kami::routes()` in its own `web` group.** The Tick would then pick up a session, and Kami's Forms would fail CSRF with a 419, because Kami would not know a session exists.

## Consequences

- **Kami never shadows a host route.** Apache serves a file in `public/` before Laravel sees the request. So when a non-Kami route answers `/sitemap.xml` or `/robots.txt`, `kami:install` does not write that file, and `kami:doctor` reports it. On kawaiidream, the host's sitemap of 27,000 images would otherwise vanish without a word. Kami's per-Locale sitemap files are always written. The public `Kami::sitemapUrls()` lists them, so the host can add them to its own index, since a sitemap index cannot contain another index. This refines ADR 0027 inside host applications. A host's site-owned `robots.txt` file is still left alone, as in ADR 0027.
- **Shared hosting and server** behave the same. Session pages cost a full boot on each request on both. On shared hosting, without OPcache, that cost is the uncached budget.
