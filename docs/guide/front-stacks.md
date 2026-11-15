# Front stacks

A type's `render()` returns any response ([Routing and rendering](routing-and-rendering.md#rendering)), so your pages may be Blade, Blade holding your own [Livewire](https://livewire.laravel.com) components, or [Inertia](https://inertiajs.com) pages. Kami guarantees the last two on both mounts, on cached pages and under the strict front policy, and tests them in CI. What Kami ships stays plain Blade: its `x-kami::` components load no framework, and the starter kit loads neither Livewire, Inertia nor Alpine.

## Livewire

### On the default mount

A component in a page of `Kami::routes()` works as is:

```php
// app/Livewire/Likes.php
namespace App\Livewire;

use Livewire\Component;

class Likes extends Component
{
    public int $count = 0;

    public function like(): void
    {
        $this->count++;
    }

    public function render()
    {
        return view('livewire.likes');
    }
}
```

```blade
{{-- resources/views/livewire/likes.blade.php --}}
<div>
    <button type="button" wire:click="like">Like</button>
    <output>{{ $count }}</output>
</div>
```

Put `<livewire:likes />` in a type's view. The page sets no cookie, so the [page cache](page-cache.md) stores it with Livewire's script and style, and the next visitor gets a working component before Laravel boots: Livewire signs a component's state with your application key, not with a session.

### The cookie the first click sets

The first click reaches Livewire's update route, which always runs your `web` group: it sets `laravel_session` and `XSRF-TOKEN`. The cookie follows the visitor's own action on a component that needs it, so it is strictly necessary, and needs no consent. Later pages still come from the page cache, which ignores the cookie on Kami's sessionless routes. Sessions are files ([Hosting](hosting.md#shared-hosting-and-a-server)), so a visitor's holds no IP address or browser name.

A cached page carries no valid CSRF token, and none is needed: Laravel accepts the update when the browser says it comes from the same origin (`Sec-Fetch-Site: same-origin`). An update from another site, or a sibling subdomain, answers 419. So does one from a browser that sends no Fetch Metadata, which every current browser sends and Safari before 16.4 does not: the page cache gave that visitor no token to send instead.

### On the session mount

With `Kami::routes(session: true)` ([Existing application](existing-application.md#keep-your-members-signed-in)), the same component reads the visitor's session, a signed-in member for one. The page is never cached and carries the visitor's token, within the uncached budget.

### Under the front policy

Kami's front policy has no nonce and no `'unsafe-eval'` ([Security](security.md#kamis-pages)). Livewire fits it under four conditions, which a browser test checks in Kami's CI:

1. **Livewire's CSP build.** Kami turns on `livewire.csp_safe` for every response its front policy covers, Kami's pages and your own `web` pages carrying `SecurityHeaders` ([Security](security.md#your-own-pages)), and `kami:install` publishes Livewire's assets to `public/vendor/livewire/` on every deploy, so those pages load the CSP build as a file. The admin keeps the full build, which Filament's templates need. Your Alpine expressions stay within that build's subset: no arrow functions, template literals, spread or globals; move longer logic to `Alpine.data()`, registered from a script file of your bundle on `alpine:init`. `kami:doctor` warns when Livewire's published assets are missing or another version's.
2. **Livewire's inline style.** Livewire injects a `<style>` that hides `wire:loading` and `wire:cloak`. Allow it by its hash, which the browser's console names when it blocks the style:

    ```php
    // config/kami.php
    'security' => [
        'csp' => [
            'style-src' => ["'sha256-…'"],
        ],
    ],
    ```

    The hash changes with Livewire's style and with `livewire.navigate.progress_bar_color`: check the console after updating Livewire. The style cannot move to your stylesheet, as turning off Livewire's asset injection would turn it off in the admin too.
3. **No progress bar.** Kami turns off `livewire.navigate.show_progress_bar` on those responses: the bar creates styles as it runs.
4. **No inline `@script` or `@assets`** in your components: put their JavaScript in your bundle.

A site that adds `'unsafe-eval'` to `script-src` in `security.csp` keeps Livewire's full build and its progress bar: Kami leaves both settings as they are. `kami:doctor` reports that source as a weakening, on every front page ([Weakening options](security.md#weakening-options)).

### A form from a component

A Livewire action submits a Form through `SubmitForm`, which runs the Form route's checks, the honeypot, the page, the throttle and validation, and throws Laravel's `ValidationException`, which Livewire shows natively ([From a Livewire component](forms.md#from-a-livewire-component)).

## Inertia

### On the default mount

Your `HandleInertiaRequests` goes to `Kami::routes()` ([Routing and rendering](routing-and-rendering.md#kamis-route)), and a type's `render()` returns an Inertia response:

```php
use App\Http\Middleware\HandleInertiaRequests;

Kami::routes(middleware: [HandleInertiaRequests::class]);
```

```php
use Inertia\Inertia;
use Psyao\Kami\Content\Entry;
use Symfony\Component\HttpFoundation\Response;

public function render(Entry $entry): Response
{
    return Inertia::render('Post', ['title' => $entry->title])->toResponse(request());
}
```

There is no session on this mount: your shared props must not read one, and Inertia's `errors` prop stays empty. The page cache stores an Inertia visit's JSON beside the page's HTML, for the same asset version only ([Page cache](page-cache.md)). Put `<x-kami::seo />` in your root view, so the first load carries the page's meta tags ([SEO](seo.md#the-head)).

Once a page has loaded a prop declared with `Inertia::once()`, Inertia's client names it in an `X-Inertia-Except-Once-Props` header on every later visit. Those visits always reach Laravel, within the uncached budget.

### On the session mount

With `Kami::routes(session: true, middleware: [HandleInertiaRequests::class])`, shared props may read the session, and `errors` holds what your own routes put there. The page is never cached.

### Rendering in the browser

Inertia's server-side rendering needs a Node process that stays up, which shared hosting forbids. Kami guarantees rendering in the browser, on shared hosting and on a server alike. On a server, SSR is yours to run and supervise; the page cache stores its HTML like any other, untested by Kami.

Without SSR, an Inertia page shows nothing until its JavaScript runs. A site choosing it gives up "no JavaScript required to show the content" for those pages: that trade is yours. On shared hosting, keep content pages in Blade, with Livewire where they need interaction.

### A form from a page

Inertia's `useForm` expects an Inertia response, with a redirect and errors in a session. Post to the Form route with `fetch()` instead, and read its [JSON answers](forms.md#the-json-answers):

```js
const response = await fetch(`/kami/forms/contact`, {
    method: 'POST',
    headers: { Accept: 'application/json' },
    body: new URLSearchParams({ ...fields, _kami_origin: `${entryId}:${locale}` }),
});
```

`_kami_origin` names the page, its Entry's id and Locale joined by a colon, as `<x-kami::form>` writes it: pass both in the page's props. The browser sends `Sec-Fetch-Site` itself, which the route checks, with the honeypot, the throttle and validation ([Protection](forms.md#protection)).

## Shared hosting and server

Livewire runs in the request, with no process: an update boots Laravel within the uncached budget, and its session is a file. Inertia renders in the browser. The page cache serves a cached page's HTML and its Inertia JSON before Laravel boots, OPcache on or off. On a server, OPcache makes each boot cheaper and Inertia's SSR may run as your own process; several web servers share `storage/` for sessions, as for the page cache.
