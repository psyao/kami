# Imports

`Import` moves a running site's content into Kami, from WordPress, Velox or any other system. It is written for rehearsals: until the day a site switches over, its importer runs again and again against fresh dumps of the old system, and each run brings Kami level with the source (ADR 0026).

## An importer

An importer is a command of your site, named `app:import:<source>`: Kami ships no runner. It opens a run, hands over one source item at a time, then closes the run:

```php
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Psyao\Kami\Content\Fields\RichText;
use Psyao\Kami\Content\RichText\Document;
use Psyao\Kami\Import\Import;
use Throwable;
use App\Content\Article;

final class ImportVelox extends Command
{
    protected $signature = 'app:import:velox';

    protected $description = 'Imports the Velox site into Kami';

    public function handle(): void
    {
        $import = Import::start('Velox');
        $body = RichText::make('body');

        try {
            DB::connection('velox')->table('posts')->orderBy('id')->chunk(200, function ($posts) use ($import, $body): void {
                foreach ($posts as $post) {
                    $import->entry('velox.post', (string) $post->id, Article::class, [
                        'fr' => ['title' => $post->title_fr, 'slug' => $post->slug_fr, 'body' => Document::fromHtml($post->body_fr, $body), 'author' => $post->author, 'status' => 'published', 'published_at' => $post->published_at],
                        'en' => ['title' => $post->title_en, 'slug' => $post->slug_en, 'body' => Document::fromHtml($post->body_en, $body), 'status' => 'published', 'published_at' => $post->published_at],
                    ], CarbonImmutable::parse($post->created_at, 'UTC'), CarbonImmutable::parse($post->updated_at, 'UTC'));
                }
            });
        } catch (Throwable $exception) {
            $import->abort($exception);
        }

        $report = $import->finish();

        $this->info("{$report->created} created, {$report->updated} updated, {$report->unchanged} unchanged, {$report->unseen} unseen.");
    }
}
```

`Import::start()` takes a label, which names the run in the [Audit log](audit-log.md), and imports into the current Site.

`entry()` takes the item's source, its Legacy id, its Content Type, its values and, optionally, its original creation and modification dates:

- **Values by Locale.** Each Locale gets the values [`SaveEntry`](publishing.md) takes in it, the Site's default Locale first. [Shared Fields](multilingual.md) go in any of them, once. An untranslatable type takes one set of values, under any key. `published_at` is one of the values, read in UTC.
- **Through the core.** Each Locale is written by `SaveEntry`, so its validation, Paths, References and Content events apply as for any write, with a null actor. A publication dated in the past falls outside `kami:announce-publications`' window and is not announced.
- **Original dates.** Once the values are saved, `Import` sets `created_at` and `updated_at` on the Entry and every Translation. Left out, they keep the time of the write. `SaveEntry` itself takes no dates, so the admin can never backdate an edit.
- **Singletons and Global Sets.** `kami:install`, which a deploy runs before the importer, creates the Entry of each [Singleton and Global Set](content-types.md#singletons), `SiteSettings` included. The first import of such a type, with no key yet, adopts that Entry rather than create a second one: it writes over it, sets its dates, keys it and counts it as created. Left out, `created_at` keeps the time `kami:install` created it. Later runs replay it as any other item.
- **Rich text.** A [RichText](content-types.md#rich-text) value goes in as the stored array or as a `Document`: an HTML string is refused. Turn HTML into a `Document` with `Document::fromHtml($html, $field)`, as the example does, giving it a Field that allows at least what the type declares (`->code()`, its heading levels), or what the Field leaves out is dropped before the save. Kami hashes a `Document` by its content.

`entry()` returns the Entry as stored.

## Media

`media()` imports a file into the [Media library](media.md), keyed and replayed like an Entry. It takes the item's source, its Legacy id, the path of a local file, its text by Locale and, optionally, its original creation and modification dates:

```php
$import->media('velox.file', (string) $file->id, storage_path("import/velox/{$file->path}"), [
    'fr' => ['alt' => $file->alt_fr, 'credit' => $file->credit],
    'en' => ['alt' => $file->alt_en],
], CarbonImmutable::parse($file->created_at, 'UTC'), CarbonImmutable::parse($file->updated_at, 'UTC'));
```

- **A local file, read in place.** Copy the old system's files next to the dump first, for instance with `rsync`. Kami reads the file where it lies and stores its own copy on `kami.media.originals_disk`.
- **Through the core.** The file and each Locale's values go through [`SaveMedia`](media.md#storing-a-file): its checks, its `alt` and `caption` per Locale, its `credit` and `decorative` in any Locale, and `MediaSaved` with a null actor. Without values, the item is stored with no text, to be filled in later.
- **Original dates.** Once saved, `Import` sets `created_at` and `updated_at` on the Media item. Its text has no dates of its own.

`media()` returns the Media item as stored. Link it from an Entry's [Media Field](content-types.md#media) by its id: import the files before the Entries that show them.

## Submissions

`submission()` copies a message sent through a [Form](forms.md) in the old system. It takes the Form, as an instance or by its handle, the values, the Locale of the page it was sent from, its original sending date and, optionally, when it was read and the Entry it was sent from:

```php
DB::connection('velox')->table('contact_messages')->orderBy('id')->chunk(200, function ($messages) use ($import): void {
    foreach ($messages as $message) {
        $import->submission('contact', [
            'subject' => $message->subject,
            'email' => $message->email,
            'message' => $message->body,
        ], $message->locale, CarbonImmutable::parse($message->created_at, 'UTC'), $message->read_at === null ? null : CarbonImmutable::parse($message->read_at, 'UTC'));
    }
});
```

- **Never notified.** A copied Submission gets no delivery and dispatches no `SubmissionStored`: nobody receives an old message again.
- **As stored.** The values keep the Form's fields only, as a visitor's post does, and the Locale must be one of the Site's. `created_at` is the sending date, `read_at` the reading date, and `updated_at` the later of the two.
- **No Legacy id.** A Submission is skipped when one is already stored with the same Form, Locale, `created_at` and values, in any key order: a replay never duplicates a message. A message with the same values sent in the same second is taken for the same one.

`submission()` returns the Submission as stored. It counts as created when copied and unchanged when skipped.

Kami tells a copied message by what is stored, so a replay copies again a Submission deleted since: one your Form's retention pruned, or one erased at its sender's request. The source wins here too, as for an Entry deleted in Kami: until switch-over, erase a message in the old system as well, and before an `--again` replay check that the source holds none you erased since.

## Legacy ids

Each imported Entry or Media item gets a key in `kami_import_keys`: its Site, its source and its Legacy id, unique together. A Submission gets none. The source names the old system and its id space, such as `velox.post` and `velox.term`, so a post and a term with the same id never meet. A key holds an Entry or a Media item, never both: a Legacy id its source already holds for an Entry fails in `media()`, and the reverse.

A key disappears with its Entry or Media item. Nothing in content, rendering or the admin reads these keys.

## Replays

Run the importer again and each item finds its key:

- **Unchanged.** Kami hashes what `entry()` received: the type, the values and the dates. For `media()`, it hashes the file's content rather than its path, the values and the dates, so a dump unpacked in another folder replays unchanged. When the hash matches the stored one, the item is skipped. Nothing is written, no event fires and `updated_at` stays put. Hand over strings, numbers and booleans, `Document`s, and dates as strings in UTC: an object such as `now()` or a model hashes differently on every run, and its item would be rewritten every time.
- **Changed.** When the hash differs, the item is rewritten from the source: **the source wins**, over any change made by hand in Kami since. Until switch-over, a fix belongs in the source or the importer, never in the admin. A change to the importer's code changes what it hands over, so the next run rewrites what it affects.
- **New.** An item with no key is created.

A changed file replaces the Media item's original within its family, keeping its id and every Entry's link to it ([Replacing a file](media.md#replacing-a-file)); a file of another family fails the item. When only the text changed, the stored original is left as it is, and the file is not checked again.

To resume an interrupted run, run it again: what was done is skipped.

## A second pass for links

An item may link to another one the run has not reached yet. Resolve each link through `find()` on the first pass, keeping the old URL when it returns `null`, then run over the items again: `find()` returns the Entry imported under a source and a Legacy id, by this run or an earlier one, or `null`, also for an Entry in the Trash, which a relation Field refuses as a target.

```php
$target = $import->find('velox.post', (string) $link->post_id);
```

The items whose links now resolve hand over new values and are rewritten; all the others are skipped. On a replay every target exists already, so the first pass hands over what is stored and nothing is rewritten. An importer that left every link as the old URL on its first pass would rewrite each linked item twice on every run.

## One item at a time

Each item is written in one transaction: the Entry, all its Translations and Fields, its dates and its key, or the Media item, its text, its dates and its key. When an item fails, `entry()` or `media()` throws a `RuntimeException` naming its source and Legacy id, and `submission()` one naming its Form, Locale and `created_at`, with the cause as its previous exception. The item leaves nothing behind, not even the original a failed `media()` wrote; the items before it stay imported, and the run stops: any later call but `find()` throws a `LogicException`, and the run records a `kami.import.failed` Audit summary. Fix the cause and run the importer again.

An exception of your importer's own, between items, such as a source it cannot read, does not go through `Import`: catch it and hand it to `abort()`, as above. `abort()` stops the run, records the `kami.import.failed` summary and throws the exception again; after a failed item it only throws it again, as the summary is already recorded. A run left neither finished nor aborted records no summary for the items it committed.

Read the source in chunks, as above, so memory stays bounded however large it is.

## The report

`finish()` closes the run and returns an `ImportReport`:

| Property | Holds |
| --- | --- |
| `created` | Items imported for the first time, Submissions copied |
| `updated` | Items rewritten because their payload changed |
| `unchanged` | Items and Submissions skipped |
| `unseen` | How many keys the run did not see |
| `unseenKeys` | Those keys, each with its `source`, `legacy_id`, `entry_id` and `media_id` |

Unseen keys belong to the sources the run touched and were not seen during it: their items are gone from the source. Kami never deletes on its own. Your importer decides, for instance by trashing them with `TrashEntry`.

The run's writes record no Audit event of their own; anything else written between them records its own, as usual. `finish()` records one `kami.import.completed` event with the label, the counts and the count of each event the run's writes would have recorded. A run that fails records one `kami.import.failed` event instead, with the label, the counts of what it committed and, when an item stopped it, the `item`.

## Running an import

An import is a command run from the command line, never a migration and never a web request.

- **Shared hosting.** Run it over SSH, in maintenance mode, as a step of your `deploy.sh`, where `max_execution_time` is 0. Nothing runs it on the web.
- **Server.** The same command, the same way.

```bash
php artisan down
php artisan app:import:velox
php artisan up
```

Once the site has switched over, Kami is the source of truth: stop running the importer. The keys stay, at no cost.

## A guard against an accidental replay

A deploy that runs the importer once, at switch-over, may run it again by mistake: a deploy repeated, the import line left in `deploy.sh`. The source would then win over everything edited in Kami since. Kami does not guard against it, as rehearsals replay on purpose; your importer does, by refusing to run once any of its keys exists, unless told to:

```php
protected $signature = 'app:import:velox {--again : Replay over an earlier import}';

public function handle(): int
{
    if (! $this->option('again') && DB::table('kami_import_keys')->where('source', 'like', 'velox.%')->exists()) {
        $this->info('Velox is imported already: nothing to do. Replay with --again.');

        return self::SUCCESS;
    }

    // …
}
```

`handle()` returns an exit code here, so the guard can exit successfully: a deploy that reaches it again goes on. Run `--again` by hand only, for instance after a failed switch-over rolled back: the source wins for Entries and Media items, and the messages already copied are skipped.
