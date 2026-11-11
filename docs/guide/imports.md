# Imports

`Import` moves a running site's content into Kami, from WordPress, Velox or any other system. It is written for rehearsals: until the day a site switches over, its importer runs again and again against fresh dumps of the old system, and each run brings Kami level with the source (ADR 0026).

## An importer

An importer is a command of your site, named `app:import:<source>`: Kami ships no runner. It opens a run, hands over one source item at a time, then closes the run:

```php
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Psyao\Kami\Import\Import;
use App\Content\Article;

final class ImportVelox extends Command
{
    protected $signature = 'app:import:velox';

    protected $description = 'Imports the Velox site into Kami';

    public function handle(): void
    {
        $import = Import::start('Velox');

        DB::connection('velox')->table('posts')->orderBy('id')->chunk(200, function ($posts) use ($import): void {
            foreach ($posts as $post) {
                $import->entry('velox.post', (string) $post->id, Article::class, [
                    'fr' => ['title' => $post->title_fr, 'slug' => $post->slug_fr, 'body' => $post->body_fr, 'author' => $post->author, 'status' => 'published', 'published_at' => $post->published_at],
                    'en' => ['title' => $post->title_en, 'slug' => $post->slug_en, 'body' => $post->body_en, 'status' => 'published', 'published_at' => $post->published_at],
                ], CarbonImmutable::parse($post->created_at, 'UTC'), CarbonImmutable::parse($post->updated_at, 'UTC'));
            }
        });

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

`entry()` returns the Entry as stored.

## Legacy ids

Each imported Entry gets a key in `kami_import_keys`: its Site, its source and its Legacy id, unique together. The source names the old system and its id space, such as `velox.post` and `velox.term`, so a post and a term with the same id never meet.

A key disappears with its Entry. Nothing in content, rendering or the admin reads these keys.

## Replays

Run the importer again and each item finds its key:

- **Unchanged.** Kami hashes what `entry()` received: the type, the values and the dates. When the hash matches the stored one, the item is skipped. Nothing is written, no event fires and `updated_at` stays put.
- **Changed.** When the hash differs, the item is rewritten from the source: **the source wins**, over any change made by hand in Kami since. Until switch-over, a fix belongs in the source or the importer, never in the admin. A change to the importer's code changes what it hands over, so the next run rewrites what it affects.
- **New.** An item with no key is created.

To resume an interrupted run, run it again: what was done is skipped.

## A second pass for links

An item may link to another one the run has not reached yet. Leave the link as the old URL on the first pass, then run over the items again: `find()` returns the Entry imported under a source and a Legacy id, by this run or an earlier one, or `null`.

```php
$target = $import->find('velox.post', (string) $link->post_id);
```

The items whose links now resolve hand over new values and are rewritten; all the others are skipped.

## One item at a time

Each item is written in one transaction: the Entry, all its Translations and Fields, its dates and its key. When an item fails, `entry()` throws a `RuntimeException` naming its source and Legacy id, with the cause as its previous exception. The item leaves nothing behind, the items before it stay imported, and the run stops: fix the cause and run the importer again.

Read the source in chunks, as above, so memory stays bounded however large it is.

## The report

`finish()` closes the run and returns an `ImportReport`:

| Property | Holds |
| --- | --- |
| `created` | Items imported for the first time |
| `updated` | Items rewritten because their payload changed |
| `unchanged` | Items skipped |
| `unseen` | How many keys the run did not see |
| `unseenKeys` | Those keys, each with its `source`, `legacy_id`, `entry_id` and `media_id` |

Unseen keys belong to the sources the run touched and were not seen during it: their items are gone from the source. Kami never deletes on its own. Your importer decides, for instance by trashing them with `TrashEntry`.

Between `start()` and `finish()`, writes record no Audit event of their own. `finish()` records one `kami.import.completed` event with the label, the counts and the count of each event the run's writes would have recorded. A run that fails records none.

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
