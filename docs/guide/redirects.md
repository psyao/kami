# Redirects

A Redirect keeps an old address answering: `/blog` sends visitors to `/articles`, a WordPress link `/?p=123` to the Post it named, and `/?attachment_id=12` answers 410 Gone. Redirects share Kami's paths table with the Entries' current addresses, so an address is never both live and redirected, and a request resolves in one indexed read, Redirects included (ADR 0008).

Kami writes some Redirects itself: a once-public address that moves answers 301 to the Entry's new one ([Changing an address](routing-and-rendering.md#changing-an-address)), and an Entry deleted for good leaves a 301 to its replacement or a 410. You write the others in the [admin](#the-admin), or with `SaveRedirect`, from a seeder, an [import](imports.md) or your own code.

## The admin

The admin's **Redirects** page, at `/admin/redirects`, lists the Site's Redirects, Kami's own included. A User needs `kami.redirects.manage` to see it ([Users and Roles](users-and-roles.md#permissions)); an administrator holding `kami.*` has it.

A row shows the source, its Locale when the Site has several, the status, the target, an Entry's title, an address or **None** for a 410, and the date of its last change. It reads **Unknown** for a Redirect Kami wrote before 0.10.0. The table is searched by source and filtered by status.

- **New redirect** opens a form: the source, the Locale, the status, and for a 301 or 302 a target, an Entry searched by title or an address. The picker offers the Entries of the types the User may view; another type's target shows as restricted. A refused save keeps the form open, its error on the field at fault ([Refusals](#refusals)).
- Each row's **Edit** changes the status and the target, a Redirect Kami wrote included. The source and the Locale stay as they are: to change them, delete the Redirect and add another.
- **Delete** asks first. The source then answers as if it had never redirected.

Every change goes through `SaveRedirect` and `DeleteRedirect`, so the Audit log and the page cache see it as they see a seeder's.

## Writing a Redirect

`SaveRedirect` takes a source, a status and a target:

<!-- kami:example redirect -->
```php
use Psyao\Kami\Redirects\Actions\SaveRedirect;

$redirects = resolve(SaveRedirect::class);

$redirects->handle('/blog', 301, '/articles');
$redirects->handle('/?p=123', 301, $post); // an Entry, such as one `$import->find()` returns
$redirects->handle('/?attachment_id', 410);
$redirects->handle('/press', 302, 'https://example.com/press', locale: 'en');
```

- **Status.** 301 for a permanent move, 302 for a temporary one, 410 for content gone for good.
- **Target.** An `Entry` sends visitors to its current URL, wherever it moves later, so the Redirect never goes stale. An address, `https://…`, `http://…` or `/…`, is sent as you wrote it. A 410 has none.
- **Locale.** A Redirect answers in one Locale, the default one unless you name another. Its source leaves out the Locale's segment: `'/blog'` with `locale: 'en'` answers at `/en/blog`.

Saving a source that already redirects changes that Redirect, Kami's own included, so an import can run again. `DeleteRedirect` removes one:

<!-- kami:example redirect -->
```php
use Psyao\Kami\Redirects\Actions\DeleteRedirect;
use Psyao\Kami\Redirects\Redirect;

resolve(DeleteRedirect::class)->handle(Redirect::query()->where('path', 'blog')->sole());
```

Neither action authorises: check the actor's Permission first, as for [`SaveEntry`](publishing.md). Pass the actor as `actor:` to record them in the [Audit log](audit-log.md). Each write dispatches `RedirectSaved` or `RedirectDeleted` once it has committed, and makes the [page cache](page-cache.md) start afresh.

## Sources

A source is stored the way Kami compares addresses: no trailing slash, lowercase, percent-decoded and NFC-normalised. `/Blog/` and `blog` are the same source.

It may carry a query string, normalised too: keys sorted, case and encoding fixed, so `/?B=2&a=1` and `/?a=1&b=2` are one source. A key alone matches any value: `/?attachment_id` answers `/?attachment_id=12` and `/?attachment_id=99`. A request matches the most specific source:

1. the path with its whole query string;
2. the path with one of its parameters (`/?p=123` matches `/?p=123&utm_source=newsletter`);
3. the path with one of its keys alone;
4. the path alone.

Other parameters are ignored, as is every pair past the 20 that sort first; a source holds 20 at most. A request whose query string matches no Redirect renders the page at its path as usual, and is never stored in the page cache.

## Refusals

`SaveRedirect` throws Laravel's `ValidationException`, changing nothing, on the input at fault:

- `source`: an Entry's current address, which the error names, since that Entry is where to change it; an address Kami answers itself (`media/…`, `sitemap.xml`…), an Archive's or a Feed's; in the default Locale, one starting with another Locale's code; a character no URL carries there, such as a space; more than 500 characters; one a concurrent save, a Redirect's or an Entry's, takes first.
- `status`: anything but 301, 302 or 410.
- `target`: none for a 301 or 302, one for a 410; an Entry in the Trash, of another Site or with no URL; an address that is not `https://`, `http://` or `/…`, such as `//host` or `javascript:`; a relative address that is itself a Redirect source in that Locale, or the source itself.

The last rule keeps visitors to one Redirect: point to the destination instead. An Entry target never chains, as it is read at request time. Kami does not compare an absolute URL, and a Redirect written later at a target's address adds one step: delete or change one of the two.

## Reading Redirects

`Psyao\Kami\Redirects\Redirect` reads the Redirects, never a current address. `path` holds the source, `status` its status, `entry_id` or `target` its target, and `entry` the target Entry while it is visible:

<!-- kami:example -->
```php
use Psyao\Kami\Redirects\Redirect;

Redirect::query()->where('locale', 'fr')->with('entry')->orderBy('path')->get();
```

Its `created_at` and `updated_at` are null for the Redirects Kami wrote before 0.10.0.

## Hidden sources

A Redirect saved before a Locale, an Archive or a Feed was declared at its address, or, in the default Locale, before Kami answered that segment itself, never answers: the Locale, the Archive, the Feed or Kami's own route takes the request first. [`kami:doctor`](installation.md#checking-the-environment) lists such sources, as it lists the Entries' hidden Paths ([Reserved paths](routing-and-rendering.md#reserved-paths)). Delete them on the Redirects page, or with `DeleteRedirect`.

## Patterns

A Redirect holds one exact address. A pattern, such as every `/page/{n}`, stays a route in your application:

<!-- kami:example -->
```php
use Illuminate\Support\Facades\Route;

Route::get('/page/{n}', fn () => redirect('/articles', 301))->whereNumber('n');
```

Your routes always win over Kami's fallback route, which answers every address left.

## Shared hosting and server

The same on both: one indexed read per request, Redirects included, and nothing to schedule.
