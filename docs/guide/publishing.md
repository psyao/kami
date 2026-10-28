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

### In the admin

The create form saves a Draft with **Save draft**, or publishes with **Publish**. The edit form's **Save changes** keeps the Translation's state, and **Publish** or **Unpublish** changes it. **Publish** with the publication date blank publishes now; a past date backdates, a date to come schedules. The date is entered and listed in the Site's time zone, `timezone` in `config/kami.php`, and stored in UTC. Publish and Unpublish appear only to a User with the type's `publish` Permission, and only that User may change the publication date: without it, a Scheduled Translation cannot be brought forward. Each Locale's Translation is published from its own screen, described in [Multilingual](multilingual.md#in-the-admin); **View on the site** opens its public URL while it is visible.

## Validation

Invalid values throw Laravel's `Illuminate\Validation\ValidationException`, with each error on the value it concerns, as a form request would. The admin shows them on their Field; your code catches them like any other validation error. Nothing is written when a save fails.

On every save, Drafts included:

- A Field's `->rules([...])` apply. They take any Laravel rule; a blank value passes them unless the rule is implicit, such as `required`.
- A handle the type does not declare is refused.
- `status` is `draft` or `published`; `published_at` is a date.
- `''` and `[]` are stored as `null`, so a blank Translatable Field falls back to the default Locale.

When the Translation is saved as `published`, Scheduled included, `title`, `slug` and every `->required()` Field must have a value, Shared Fields too. A Draft may be incomplete, even without a title:

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
