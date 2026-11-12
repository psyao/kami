<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Psyao\Kami\Audit\Actions\RecordAuditEvent;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Actions\TrashEntry;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Events\EntrySaved;
use Psyao\Kami\Import\Import;
use Psyao\Kami\Import\ImportReport;
use Psyao\Kami\Media\Events\MediaSaved;
use Psyao\Kami\Media\Media;
use Workbench\App\Content\Article;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-11-11 10:00:00')));

/**
 * One Velox post, as an importer hands it over: French first, the Site's default Locale.
 *
 * @return array<string, array<string, mixed>>
 */
function veloxPost(string $title, array $french = []): array
{
    return ['fr' => ['title' => $title, 'author' => 'Ada', ...$french], 'en' => ['title' => "{$title} (en)"]];
}

/** @return array<int, array{string, string|null}> Event name and context of each Audit row, oldest first. */
function importAudit(): array
{
    return DB::table('kami_audit_events')->oldest('occurred_at')->get(['event', 'context'])
        ->map(fn (object $row): array => [$row->event, json_decode($row->context, true)])->all();
}

it('creates each item once, skips an unchanged replay, and rewrites only the item that changed', function (): void {
    $import = Import::start('Velox');
    $one = $import->entry('velox.post', '1', Article::class, veloxPost('Un'));
    $two = $import->entry('velox.post', '2', Article::class, veloxPost('Deux'));
    $first = $import->finish();

    $this->travel(1)->hour();
    Event::fake([EntrySaved::class]);
    $import = Import::start('Velox');
    $import->entry('velox.post', '1', Article::class, veloxPost('Un'));
    $import->entry('velox.post', '2', Article::class, veloxPost('Deux'));
    $replay = $import->finish();

    $this->travel(1)->hour();
    $import = Import::start('Velox');
    $import->entry('velox.post', '1', Article::class, veloxPost('Un'));
    $changed = $import->entry('velox.post', '2', Article::class, veloxPost('Deux, revu'));
    $change = $import->finish();

    expect([$first->created, $first->updated, $first->unchanged])->toBe([2, 0, 0])
        ->and([$replay->created, $replay->updated, $replay->unchanged])->toBe([0, 0, 2])
        ->and([$change->created, $change->updated, $change->unchanged])->toBe([0, 1, 1])
        ->and($changed->id)->toBe($two->id)
        ->and($changed->title)->toBe('Deux, revu')
        ->and(Entry::query()->withUnpublished()->count())->toBe(2)
        ->and(Entry::query()->withUnpublished()->findOrFail($one->id)->updated_at->toDateTimeString())->toBe('2026-11-11 10:00:00')
        ->and(DB::table('kami_import_keys')->where('legacy_id', '1')->value('seen_at'))->toBe('2026-11-11 12:00:00');
    Event::assertDispatchedTimes(EntrySaved::class, 2);
});

it('lets the source win over a change made in Kami once the importer’s payload changes', function (): void {
    $import = Import::start('Velox');
    $entry = $import->entry('velox.post', '1', Article::class, veloxPost('Un'));
    $import->finish();
    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Changed by hand'], $entry, 'fr');

    $import = Import::start('Velox');
    $kept = $import->entry('velox.post', '1', Article::class, veloxPost('Un'));
    $import->finish();
    $import = Import::start('Velox');
    $rewritten = $import->entry('velox.post', '1', Article::class, veloxPost('Un', ['summary' => 'A summary the importer now adds.']));
    $import->finish();

    expect($kept->title)->toBe('Changed by hand')
        ->and($rewritten->title)->toBe('Un')
        ->and($rewritten->summary)->toBe('A summary the importer now adds.');
});

it('keeps the original dates on the Entry and its Translations, and a change of date rewrites the item', function (): void {
    $created = CarbonImmutable::parse('2019-03-04 05:06:07', 'UTC');
    $updated = CarbonImmutable::parse('2021-08-09 10:11:12', 'UTC');

    $import = Import::start('Velox');
    $entry = $import->entry('velox.post', '1', Article::class, veloxPost('Un'), $created, $updated);
    $import->entry('velox.post', '1', Article::class, veloxPost('Un'), $created, $updated->addDay());
    $report = $import->finish();

    expect($entry->created_at->toDateTimeString())->toBe('2019-03-04 05:06:07')
        ->and($entry->updated_at->toDateTimeString())->toBe('2021-08-09 10:11:12')
        ->and(DB::table('kami_translations')->orderBy('locale')->get(['locale', 'created_at', 'updated_at'])->map(fn (object $row): array => (array) $row)->all())->toBe([
            ['locale' => 'en', 'created_at' => '2019-03-04 05:06:07', 'updated_at' => '2021-08-10 10:11:12'],
            ['locale' => 'fr', 'created_at' => '2019-03-04 05:06:07', 'updated_at' => '2021-08-10 10:11:12'],
        ])
        ->and($report->updated)->toBe(1);
});

it('writes Shared Fields once, read in every Locale', function (): void {
    $import = Import::start('Velox');
    $entry = $import->entry('velox.post', '1', Article::class, veloxPost('Un'));
    $import->finish();

    $entry->readIn('en');

    expect($entry->author)->toBe('Ada')
        ->and($entry->title)->toBe('Un (en)');
});

it('finds an imported Entry by its Legacy id, for a second pass over forward links', function (): void {
    $import = Import::start('Velox');
    $target = $import->entry('velox.post', '2', Article::class, veloxPost('Deux'));

    expect($import->find('velox.post', '2')?->id)->toBe($target->id)
        ->and($import->find('velox.post', '3'))->toBeNull()
        ->and($import->find('velox.page', '2'))->toBeNull();

    $import->finish();
});

it('finds no Entry in the Trash, which a relation Field refuses as a target', function (): void {
    $import = Import::start('Velox');
    resolve(TrashEntry::class)->handle($import->entry('velox.post', '2', Article::class, veloxPost('Deux')));

    expect($import->find('velox.post', '2'))->toBeNull();

    $import->finish();
});

it('reports the keys this run did not see, among the sources it touched, and deletes nothing', function (): void {
    $import = Import::start('Velox');
    $import->entry('velox.post', '1', Article::class, veloxPost('Un'));
    $gone = $import->entry('velox.post', '2', Article::class, veloxPost('Deux'));
    $import->entry('velox.page', '1', Article::class, veloxPost('Page'));
    $import->finish();

    $this->travel(1)->hour();
    $import = Import::start('Velox');
    $import->entry('velox.post', '1', Article::class, veloxPost('Un'));
    $report = $import->finish();

    expect($report->unseen)->toBe(1)
        ->and($report->unseenKeys)->toBe([['source' => 'velox.post', 'legacy_id' => '2', 'entry_id' => $gone->id, 'media_id' => null]])
        ->and(Entry::query()->withUnpublished()->count())->toBe(3);
});

it('names the source and the Legacy id of a failed item, and leaves nothing of it', function (array $values): void {
    $import = Import::start('Velox');

    expect(fn (): Entry => $import->entry('velox.post', '7', Article::class, $values))
        ->toThrow(RuntimeException::class, 'The import of [velox.post] item [7] failed:');

    expect(DB::table('kami_entries')->count())->toBe(0)
        ->and(DB::table('kami_translations')->count())->toBe(0)
        ->and(DB::table('kami_import_keys')->count())->toBe(0);
})->with([
    'an invalid value' => [['fr' => ['title' => 'Sept', 'author' => 'Ada'], 'en' => ['pages' => 'many']]],
    'a payload that is not UTF-8' => [['fr' => ['title' => "Sept \xB1"]]],
]);

it('names the item a database error stops, whose code is no integer', function (): void {
    Event::listen('eloquent.saving: ' . Entry::class, function (): never {
        // PDO's code is a SQLSTATE string, which a QueryException keeps.
        throw new QueryException('sqlite', 'insert into kami_entries', [], new class('Integrity constraint violation') extends PDOException
        {
            protected $code = '23000';
        });
    });
    $import = Import::start('Velox');

    expect(fn (): Entry => $import->entry('velox.post', '7', Article::class, veloxPost('Sept')))
        ->toThrow(RuntimeException::class, 'The import of [velox.post] item [7] failed:');
});

it('fails a changed item whose Entry is in the Trash, and skips an unchanged one', function (): void {
    $import = Import::start('Velox');
    resolve(TrashEntry::class)->handle($import->entry('velox.post', '1', Article::class, veloxPost('Un')));
    $import->finish();

    $import = Import::start('Velox');
    $skipped = $import->entry('velox.post', '1', Article::class, veloxPost('Un'));

    expect($skipped->trashed())->toBeTrue()
        ->and(fn (): Entry => $import->entry('velox.post', '1', Article::class, veloxPost('Un, revu')))->toThrow(RuntimeException::class, 'is in the Trash');
});

it('keeps the Translation of a Locale the source no longer gives', function (): void {
    $import = Import::start('Velox');
    $import->entry('velox.post', '1', Article::class, veloxPost('Un'));
    $entry = $import->entry('velox.post', '1', Article::class, ['fr' => ['title' => 'Un, revu', 'author' => 'Ada']]);
    $import->finish();

    $entry->readIn('en');

    expect($entry->title)->toBe('Un (en)');
});

it('records one Audit summary for the run, and no event per item', function (): void {
    $import = Import::start('Velox');
    $import->entry('velox.post', '1', Article::class, veloxPost('Un', ['status' => 'published']));
    $import->entry('velox.post', '2', Article::class, veloxPost('Deux'));
    $import->finish();

    // MySQL's JSON type sorts the keys.
    expect(importAudit())->toEqual([
        ['kami.import.completed', ['label' => 'Velox', 'created' => 2, 'updated' => 0, 'unchanged' => 0, 'unseen' => 0, 'kami.entries.published' => 1]],
    ]);
});

it('records Audit events again once a run has failed', function (): void {
    $import = Import::start('Velox');

    try {
        $import->entry('velox.post', '7', Article::class, ['fr' => ['pages' => 'many']]);
    } catch (RuntimeException) {
    }

    resolve(RecordAuditEvent::class)->handle('acme.after.failure');

    expect(importAudit())->toBe([['acme.after.failure', []]])
        ->and(fn (): Entry => $import->entry('velox.post', '8', Article::class, veloxPost('Huit')))->toThrow(LogicException::class, 'This Import is over')
        ->and(fn (): ImportReport => $import->finish())->toThrow(LogicException::class, 'This Import is over');
});

it('finishes a run once', function (): void {
    $import = Import::start('Velox');
    $import->finish();

    expect(fn (): ImportReport => $import->finish())->toThrow(LogicException::class, 'This Import is over')
        ->and(importAudit())->toHaveCount(1);
});

it('sets exactly one of entry_id and media_id, which engines with a check refuse otherwise', function (): void {
    $import = Import::start('Velox');
    $entry = $import->entry('velox.post', '1', Article::class, veloxPost('Un'));
    $import->finish();
    $media = (string) Str::ulid();
    DB::table('kami_media')->insert(['id' => $media, 'site' => 'default', 'file' => 'a.jpg', 'name' => 'a', 'mime' => 'image/jpeg', 'family' => 'image', 'size' => 1, 'checksum' => str_repeat('0', 64)]);
    $key = fn (?string $entryId, ?string $mediaId): Closure => fn () => DB::transaction(fn () => DB::table('kami_import_keys')->insert([
        'id' => (string) Str::ulid(), 'site' => 'default', 'source' => 'raw', 'legacy_id' => (string) Str::ulid(),
        'entry_id' => $entryId, 'media_id' => $mediaId, 'checksum' => str_repeat('0', 64), 'seen_at' => now(),
    ]));

    expect(DB::table('kami_import_keys')->get(['entry_id', 'media_id'])->map(fn (object $row): array => (array) $row)->all())
        ->toBe([['entry_id' => $entry->id, 'media_id' => null]]);

    if (DB::getDriverName() !== 'sqlite') {
        expect($key(null, null))->toThrow(QueryException::class, 'kami_import_keys_one_target')
            ->and($key($entry->id, $media))->toThrow(QueryException::class, 'kami_import_keys_one_target');
    }
});

it('imports a Media item with its text by Locale and its original dates, skips an unchanged file, and replaces a changed one', function (): void {
    Storage::fake('local');
    $created = CarbonImmutable::parse('2019-03-04 05:06:07', 'UTC');
    $values = ['fr' => ['alt' => 'Un carré rouge', 'credit' => 'Ada'], 'en' => ['alt' => 'A red square']];

    $import = Import::start('Velox');
    $media = $import->media('velox.file', '7', fixture('media/Holiday Photo.JPG'), $values, $created, $created);
    $first = $import->finish();

    Event::fake([MediaSaved::class]);
    $import = Import::start('Velox');
    $import->media('velox.file', '7', fixture('media/Holiday Photo.JPG'), $values, $created, $created);
    $replay = $import->finish();

    $import = Import::start('Velox');
    $replaced = $import->media('velox.file', '7', fixture('media/rotated-gps.jpg'), $values, $created, $created);
    $change = $import->finish();

    expect([$first->created, $replay->unchanged, $change->updated])->toBe([1, 1, 1])
        ->and($media->credit)->toBe('Ada')
        ->and($media->translations->pluck('alt', 'locale')->sortKeys()->all())->toBe(['en' => 'A red square', 'fr' => 'Un carré rouge'])
        ->and($media->created_at->toDateTimeString())->toBe('2019-03-04 05:06:07')
        ->and($media->updated_at->toDateTimeString())->toBe('2019-03-04 05:06:07')
        ->and($replaced->id)->toBe($media->id)
        ->and($replaced->checksum)->toBe(hash_file('sha256', fixture('media/rotated-gps.jpg')))
        ->and(Media::query()->count())->toBe(1)
        ->and(DB::table('kami_import_keys')->get(['entry_id', 'media_id'])->map(fn (object $row): array => (array) $row)->all())
        ->toBe([['entry_id' => null, 'media_id' => $media->id]]);
    // The replacement's two Locales, with no actor; none for the skipped replay.
    Event::assertDispatchedTimes(MediaSaved::class, 2);
    Event::assertDispatched(MediaSaved::class, fn (MediaSaved $event): bool => $event->actor === null);
});

it('rewrites the text of a Media item whose file did not change, leaving its original alone', function (): void {
    Storage::fake('local');
    $import = Import::start('Velox');
    $media = $import->media('velox.file', '7', fixture('media/Holiday Photo.JPG'), ['fr' => ['alt' => 'Un carré']]);
    $import->finish();
    // A limit tightened since: the file, not read again, is not checked again.
    config(['kami.media.max_pixels' => 1]);

    $import = Import::start('Velox');
    $rewritten = $import->media('velox.file', '7', fixture('media/Holiday Photo.JPG'), ['fr' => ['alt' => 'Un carré rouge']]);
    $report = $import->finish();

    expect($report->updated)->toBe(1)
        ->and($rewritten->alt())->toBe('Un carré rouge')
        ->and($rewritten->file)->toBe($media->file)
        ->and(Storage::disk('local')->allFiles())->toBe([$media->file]);
});

it('imports a Media item with no text, in the default Locale', function (): void {
    Storage::fake('local');

    $import = Import::start('Velox');
    $media = $import->media('velox.file', '7', fixture('media/brochure.pdf'));
    $import->finish();

    expect($media->family)->toBe('document')
        ->and(Storage::disk('local')->allFiles())->toBe([$media->file]);
});

it('leaves no original of a Media item that fails', function (): void {
    Storage::fake('local');
    $import = Import::start('Velox');

    expect(fn (): Media => $import->media('velox.file', '7', fixture('media/Holiday Photo.JPG'), ['fr' => ['alt' => 'Un carré'], 'de' => ['alt' => 'Ein Quadrat']]))
        ->toThrow(RuntimeException::class, 'The import of [velox.file] item [7] failed: No Locale [de] is declared in Site [default].')
        ->and(Media::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('names the item whose file is missing or unreadable', function (Closure $path): void {
    $import = Import::start('Velox');

    expect(fn (): Media => $import->media('velox.file', '7', $path()))
        ->toThrow(RuntimeException::class, 'The import of [velox.file] item [7] failed: No readable file at')
        ->and(fn (): ImportReport => $import->finish())->toThrow(LogicException::class);
})->with([
    'missing' => [fn (): string => temporaryPath() . '/gone.jpg'],
    'unreadable' => [function (): string {
        if (posix_getuid() === 0) {
            test()->markTestSkipped('root reads any file.');
        }

        $path = tempnam(sys_get_temp_dir(), 'kami');
        file_put_contents($path, (string) file_get_contents(fixture('media/pixel.png')));
        chmod($path, 0o000);

        return $path;
    }],
]);

it('refuses a Legacy id its source already holds for the other kind of item', function (Closure $first, Closure $second): void {
    Storage::fake('local');
    $import = Import::start('Velox');
    $first($import);

    expect(fn (): mixed => $second($import))
        ->toThrow(RuntimeException::class, 'The import of [velox.node] item [1] failed: its key holds another kind of item.')
        ->and(DB::table('kami_import_keys')->count())->toBe(1);
})->with([
    'an Entry’s key handed to media()' => [fn (Import $import): Entry => $import->entry('velox.node', '1', Article::class, veloxPost('Un')), fn (Import $import): Media => $import->media('velox.node', '1', fixture('media/brochure.pdf'))],
    'a Media item’s key handed to entry()' => [fn (Import $import): Media => $import->media('velox.node', '1', fixture('media/brochure.pdf')), fn (Import $import): Entry => $import->entry('velox.node', '1', Article::class, veloxPost('Un'))],
]);

it('refuses a run into another Site than the current one', function (): void {
    expect(fn (): Import => Import::start('Velox', 'blog'))->toThrow(InvalidArgumentException::class, 'An Import writes the current Site [default] only, not [blog].')
        ->and(Import::start('Velox', 'default')->finish()->created)->toBe(0);
});
