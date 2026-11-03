# Publishing

Each Translation of an Entry has its own publication: a Draft, Scheduled or Published state, evaluated when the page is read, so nothing waits on a scheduled task (ADR 0019). Every write goes through `SaveEntry`, which applies the rules below whoever calls it: the admin, a seeder, a command or an import. Declaring types is in [Content types](content-types.md), Locales in [Multilingual](multilingual.md).

## SaveEntry does not authorise

`Psyao\Kami\Content\Actions\SaveEntry` trusts its caller, as Eloquent does (ADR 0011). It never checks who is saving or whether they may publish. Code that calls it from a request, such as a site's own controller, checks the User's Permissions first. The admin does so through its Policies.

The fifth argument is that User, or `null` for imports and the CLI. Kami records it, never checks it:

```php
app(SaveEntry::class)->handle(Article::class, $values, $entry, 'en', $request->user());

$entry->created_by; // the Creator's User key, set when the Entry is created
$entry->updated_by; // the Last modifier's, set on every save
```

Deleting a User leaves both `null` on the Entries they touched.

## Concurrent edits

Pass the Entry's `updated_at` as you loaded it, and `SaveEntry` refuses to save over a change made since, by anyone and in any Locale: the Entry is compared, and every save touches it. The `ValidationException` is on `updated_at`; its message names the Last modifier, as the panel names Users, and the time in the Site's time zone. A save from code, or by a User deleted since, names nobody.

```php
app(SaveEntry::class)->handle(Article::class, $values, $entry, 'en', $request->user(), updatedAt: $loadedAt);
```

Left out, as by imports, seeders and commands, nothing is checked. The comparison stops at the second the column holds, with no lock: two saves within the same second can both pass. The admin's edit page passes the date it loaded and shows the refusal on a notification; reloading the page shows the other change, and loses what was typed.

## States

| State | Stored as | Visible |
| --- | --- | --- |
| Draft | `status` `draft` | never |
| Scheduled | `status` `published`, `published_at` to come | from `published_at` on |
| Published | `status` `published`, `published_at` reached | yes |

A Translation that is not visible answers 404 and is left out of queries unless they call `withUnpublished()`.

```php
$save = app(SaveEntry::class);

$entry = $save->handle(Article::class, ['title' => 'Hello', 'author' => 'Ada']);                     // Draft
$save->handle(Article::class, ['status' => 'published'], $entry);                                    // Published now
$save->handle(Article::class, ['status' => 'published', 'published_at' => '2027-01-01 09:00'], $entry); // Scheduled
$save->handle(Article::class, ['status' => 'published', 'published_at' => '2020-05-01'], $entry);    // backdated
$save->handle(Article::class, ['status' => 'draft'], $entry);                                        // unpublished
```

- Publishing with no `published_at` publishes now.
- A past date backdates: the page is visible at once and shows that date.
- A date to come schedules: the page appears at that moment, with no task to run.
- Unpublishing keeps `published_at`, so republishing without a date keeps the original one.

After each save and each `kami:install`, Kami writes the Site's next visibility change, the earliest date a Scheduled Translation appears, to a file under `storage/framework/kami/`. Its format is internal: Kami's page cache reads it with no query to know when cached pages go stale, so nothing needs to run at that moment.

### In the admin

The create form saves a Draft with **Save draft**, or publishes with **Publish**. The edit form's **Save changes** keeps the Translation's state, and **Publish** or **Unpublish** changes it. The edit page's subheading shows the state, Draft, Scheduled with its date, or Published. **Publish** with the publication date blank publishes now; a past date backdates; a date to come schedules, and the button then reads **Schedule**. Unpublishing a Parent hides only the Parent: its children keep answering at their own URLs, as a Path does not follow the tree yet. The date is entered and listed in the Site's time zone, `timezone` in `config/kami.php`, and stored in UTC. Publish and Unpublish appear only to a User with the type's `publish` Permission, and only that User may change the publication date: without it, a Scheduled Translation cannot be brought forward. Each Locale's Translation is published from its own screen, described in [Multilingual](multilingual.md#in-the-admin); **View on the site** opens its public URL while it is visible.

## Preview

The create and edit pages of a type with a URL have a **Preview** button. It opens a tab named `kami-preview` showing the form as it stands, unsaved, rendered by the type's own `render()` in the Locale edited, as visitors would see the page once saved. Clicking it again refreshes that tab instead of opening another. Global Sets and types declared with no URL have no Preview.

Nothing is saved: no event, no reminder, no change to `updated_at` or to the next visibility change. The form is checked as for a Draft, so a value a save would refuse, such as text over a Field's `max`, shows its error on the form instead of a Preview, while a required value left blank does not stop it. What the User's Permissions keep from a save, such as the publication date without `publish`, stays as stored in the Preview too. An Entry the form links to appears only if visitors could read it: a Draft target is left out, as on the public page.

The Preview is held in Laravel's cache for one hour under a random token, never in the session, so the `file` and `database` stores of shared hosting serve it. It answers at `/{panel}/kami/preview/{token}` to the User who made it, signed in and holding the type's `view` Permission; anyone else, and anyone after the hour, gets a 404. Its response carries the front's headers rather than the panel's, with `X-Robots-Tag: noindex` and `Cache-Control: no-store`; only its `Cross-Origin-Opener-Policy` is `unsafe-none`, so the admin can find the tab again on the next click. To tell the editor where they are, the site's layout renders `<x-kami::preview-banner />`, which [Routing and rendering](routing-and-rendering.md#the-preview-banner) describes.

## Validation

Invalid values throw Laravel's `Illuminate\Validation\ValidationException`, with each error on the value it concerns, as a form request would. The admin shows them on their Field; your code catches them like any other validation error. Nothing is written when a save fails.

On every save, Drafts included:

- A Field's `->rules([...])` apply. They take any Laravel rule; a blank value passes them unless the rule is implicit, such as `required`.
- A handle the type does not declare is refused.
- `status` is `draft` or `published`; `published_at` is a date.
- `''` and `[]` are stored as `null`, so a blank Translatable Field falls back to the default Locale.
- A value fits its column: 255 characters in a `string` column, 65,535 bytes in a `text` one, on every engine.
- A slug can answer at its URL: no space, control character, `?`, `#`, `%` or `\`, no empty segment and no `.` or `..` segment. Accents and `/` between segments are fine. In the default Locale, a Path may not start with another Locale's code, which would answer in that Locale: a type at the root cannot take the slug `en`.

When the Translation is saved as `published`, Scheduled included, `title`, `slug` and every `->required()` Field must have a value, Shared Fields too. A Draft may be incomplete, even without a title, but it cannot blank a `->required()` Shared Field holding a value while another Translation of the Entry is Published or Scheduled, as that Translation reads it. Neither can a `'*'` call:

```php
public function fields(): array
{
    return [
        Text::make('summary')->rules(['max:200']),
        Text::make('author')->shared()->required(),
    ];
}
```

Values left out of a call keep their stored ones, and the rules apply to the Translation as it will be once saved: publishing a complete Draft takes only `['status' => 'published']`.

### Scope

Each value is written to its scope. With a Locale, a Shared Field goes to the Entry's one shared value and a Translatable Field to that Locale. With the Locale `'*'`, a translatable type writes only its Shared Fields: no Translation is created, and `title`, `slug`, `status`, `published_at` or a Translatable Field in that call is refused. An untranslatable type always writes its single `'*'` Translation, whatever Locale is passed.

## Slugs and Paths

A blank slug is made from the title: `Hello world` gives `hello-world`. A given slug is stored lowercase, NFC, percent-decoded and without leading or trailing slash: `/Caf%C3%A9/` gives `café`. The Translation's Path is its type's Prefix and its slug, as [Routing and rendering](routing-and-rendering.md) describes.

A Path is unique within a Site and Locale. Saving a Translation whose Path another one holds there fails on `slug`; the same slug in another Locale or Site is fine. Drafts hold their Path too, so a page does not lose its address while it is being written.

Changing a slug moves the Path: the old address answers 404. Kami does not redirect it yet.

In the admin form, leaving the title field fills the slug with what saving would derive, in the Locale of the screen, for a Translation with no stored slug. It keeps following the title while the slug is blank or still the slug of the previous title, so a slug typed by hand is never overwritten; emptying it lets filling resume. A stored slug never changes with the title: its help text warns instead that changing it moves the address.

## Reacting to content

`SaveEntry` dispatches Content events, in `Psyao\Kami\Content\Events`, so your code reacts to a write without changing it (ADR 0029). There is no event before a write and none can cancel one: validation belongs to the type's declaration.

| Event | Dispatched when | Properties |
| --- | --- | --- |
| `EntrySaved` | every save | `entry`, `locale`, `created`, `actor` |
| `EntryPublished` | the save made the Translation visible: published now or backdated | `entry`, `locale`, `scheduled`, `actor` |
| `EntryUnpublished` | the save returned a published Translation to Draft | `entry`, `locale`, `actor` |

- `entry` is the Entry as saved, with its Translations and Field values loaded; `locale` is the Locale written, or `'*'` for an untranslatable type and a Shared-only call. `created` is `true` when the save created the Entry, not when it added a Locale. `actor` is the User passed to `SaveEntry`, `null` for imports and the CLI.
- Scheduling a Translation dispatches `EntrySaved` only: no event fires yet when its moment comes, so `scheduled` is always `false` for now.
- Events are dispatched once the write is committed. When you call `SaveEntry` inside your own `DB::transaction()`, they wait for that transaction to commit and are dropped if it rolls back, so a listener never sees a write that did not happen.
- An exception thrown by a listener reaches the caller, after the write is committed: a failed side effect is visible, never silent.

Listen to them as to any Laravel event, for instance in a service provider's `boot()`:

```php
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Psyao\Kami\Content\Events\EntryPublished;

Event::listen(function (EntryPublished $event) {
    Mail::to('editors@example.com')->send(new Published($event->entry->url($event->locale)));
});
```

Listeners run in the request, synchronously. A listener that implements `ShouldQueue` waits for a queue worker, which shared hosting does not have; there, `defer()` runs slow work after the response, at the risk of losing it. Kami's own work (Paths, validation) never listens to these events, so faking them in a test changes nothing else.
