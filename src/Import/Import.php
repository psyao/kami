<?php

declare(strict_types=1);

namespace Psyao\Kami\Import;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Psyao\Kami\Audit\Actions\RecordAuditEvent;
use Psyao\Kami\Audit\AuditContext;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Content\RichText\Document;
use Psyao\Kami\Content\Singleton;
use Psyao\Kami\Forms\Form;
use Psyao\Kami\Forms\Internal\Forms;
use Psyao\Kami\Forms\Submission;
use Psyao\Kami\Kami;
use Psyao\Kami\Media\Actions\SaveMedia;
use Psyao\Kami\Media\Media;
use Psyao\Kami\Sites\Internal\Site;
use RuntimeException;
use Throwable;

/**
 * Imports a site's content from another system, again and again until switch-over (ADR 0026). Each item gets a Legacy id, unique to its source; a replay skips an item whose payload is unchanged and rewrites one whose payload changed: the source wins. Kami deletes nothing: `finish()` reports the items a run did not see.
 *
 * An importer is a site command, `app:import:<source>`, run from the CLI: `start()`, one call per source item, `find()` for a second pass over forward links, then `finish()`.
 */
final class Import
{
    private int $created = 0;

    private int $updated = 0;

    private int $unchanged = 0;

    /** @var array<string, true> */
    private array $sources = [];

    /** @var array<string, int> The count of each Audit event the run's writes would have recorded. */
    private array $events = [];

    /** Once an item failed or the run finished: a run is not resumed, it is run again. */
    private bool $over = false;

    private function __construct(
        private readonly string $label,
        private readonly string $site,
        private readonly CarbonImmutable $startedAt,
    ) {}

    /**
     * Opens a run into a Site, the current one when `$site` is null. The run's writes record no Audit event of their own: the run records one summary, when it finishes or fails.
     *
     * @param  string  $label  Names the run in its Audit summary, such as `Velox`.
     *
     * @throws InvalidArgumentException When the label is over 255 characters, or `$site` names another than the current one.
     */
    public static function start(string $label, ?string $site = null): self
    {
        RecordAuditEvent::refuseLongContext(['label' => $label]);
        $current = Site::current()->handle;

        // ponytail: the current Site only, as everywhere until V1 multisite resolves another.
        if (($site ?? $current) !== $current) {
            throw new InvalidArgumentException("An Import writes the current Site [{$current}] only, not [{$site}].");
        }

        // Stored to the second, as `seen_at` is: what this run sees is never older.
        return new self($label, $current, now()->toImmutable()->startOfSecond());
    }

    /**
     * Imports one source item as an Entry, through `SaveEntry`, with its original dates: in one transaction, whole or not at all. An item whose payload, the arguments here, did not change since its last import is skipped. The first import of a Singleton or a Global Set adopts the Entry `kami:install` created for it.
     *
     * @param  string  $source  The system and its id space, such as `wordpress.post`.
     * @param  string  $legacyId  The item's id in the source.
     * @param  class-string<ContentType>|string  $type  The Content Type, by class or handle.
     * @param  array<string, array<string, mixed>>|(Closure(): array<string, array<string, mixed>>)  $values  The values `SaveEntry` takes, by Locale, the default Locale first. Shared Fields go in any of them. An untranslatable type takes one, under any key. A closure returning them fails the item, named, when it throws: a `Document::fromHtml()` that refuses the HTML, for instance.
     * @param  CarbonInterface|null  $createdAt  Set on the Entry and its Translations; the time of the first import when null.
     * @param  CarbonInterface|null  $updatedAt  Set on the Entry and its Translations; the time of the write when null.
     *
     * @throws RuntimeException When the item fails, naming the source and the Legacy id: the run stops there, and the item leaves nothing behind.
     * @throws LogicException When the run is over.
     */
    public function entry(string $source, string $legacyId, string $type, array | Closure $values, ?CarbonInterface $createdAt = null, ?CarbonInterface $updatedAt = null): Entry
    {
        $this->refuseOver();

        try {
            $values = $values instanceof Closure ? $values() : $values;

            // Before the checksum and any save: a Singleton's installed Entry would otherwise pass as the item, with nothing written.
            if ($values === []) {
                throw new InvalidArgumentException('An item holds the values of one Locale at least.');
            }
        } catch (Throwable $exception) {
            $this->fail("[{$source}] item [{$legacyId}]", $exception);
        }

        $dates = $this->dates($createdAt, $updatedAt);

        $id = $this->item($source, $legacyId, 'entry_id', [$type, $values, $dates], function (?string $id) use ($type, $values, $dates): string {
            $save = resolve(SaveEntry::class);
            $entry = $id === null ? $this->installedEntry($type) : Entry::query()->withUnpublished()->withTrashed()->findOrFail($id);

            foreach ($values as $locale => $localeValues) {
                $entry = $save->handle($type, $localeValues, $entry, (string) $locale);
            }

            // After the saves, which take no timestamps: the admin cannot backdate an edit (ADR 0026).
            if ($dates !== []) {
                DB::table('kami_entries')->where('id', $entry->id)->update($dates);
                DB::table('kami_translations')->where('entry_id', $entry->id)->update($dates);
            }

            return $entry->id;
        });

        return Entry::query()->withUnpublished()->withTrashed()->findOrFail($id);
    }

    /**
     * Imports one source file as a Media item, through `SaveMedia`, with its original dates: in one transaction, whole or not at all, its original deleted again if it fails. An item whose file content and arguments did not change since its last import is skipped; a changed file replaces the stored one, within its family.
     *
     * @param  string  $source  The system and its id space, such as `wordpress.attachment`.
     * @param  string  $legacyId  The item's id in the source.
     * @param  string  $path  A local file, read in place.
     * @param  array<string, array<string, mixed>>  $values  The values `SaveMedia` takes, by Locale: `alt` and `caption` in each, `credit` and `decorative` in any of them. None: the item is stored with no text.
     * @param  CarbonInterface|null  $createdAt  Set on the Media item; the time of the first import when null.
     * @param  CarbonInterface|null  $updatedAt  Set on the Media item; the time of the write when null.
     *
     * @throws RuntimeException When the item fails, naming the source and the Legacy id: the run stops there, and the item leaves nothing behind.
     * @throws LogicException When the run is over.
     */
    public function media(string $source, string $legacyId, string $path, array $values = [], ?CarbonInterface $createdAt = null, ?CarbonInterface $updatedAt = null): Media
    {
        $this->refuseOver();
        $dates = $this->dates($createdAt, $updatedAt);
        // The file's content, never its path: a dump unpacked elsewhere replays as unchanged. One it cannot read fails in `SaveMedia`, within the item.
        $checksum = is_file($path) && is_readable($path) ? hash_file('sha256', $path) : null;

        $id = $this->item($source, $legacyId, 'media_id', [$checksum, $values, $dates], function (?string $id) use ($path, $checksum, $values, $dates): string {
            $save = resolve(SaveMedia::class);
            $media = $id === null ? null : Media::query()->findOrFail($id);
            // The same file again: only its text changed, so it is neither read nor checked a second time.
            $file = $media instanceof Media && $media->checksum === $checksum ? null : $path;

            foreach ($values ?: [Site::current()->defaultLocale() => []] as $locale => $localeValues) {
                $media = $save->handle($file, $localeValues, $media, (string) $locale);
                $file = null;
            }

            // After the saves, which take no timestamps; Media Translations have none.
            if ($dates !== []) {
                DB::table('kami_media')->where('id', $media->id)->update($dates);
            }

            return $media->id;
        });

        return Media::query()->findOrFail($id);
    }

    /**
     * Copies a Form Submission with its original dates and read state, never notified: no delivery, no `SubmissionStored`. It gets no Legacy id: one already stored with the same Form, Locale, `created_at` and data is skipped, so a replay never duplicates a message.
     *
     * @param  Form|string  $form  The declared Form, or its handle.
     * @param  array<mixed>  $data  Its values, keyed by the Form's `rules()`; any other key is dropped.
     * @param  string  $locale  One of the current site's Locales.
     * @param  CarbonInterface|null  $readAt  When it was read; null when it is unread.
     * @param  Entry|null  $entry  The page it was sent from.
     *
     * @throws RuntimeException When it fails, naming the Form, the Locale and `created_at`: the run stops there.
     * @throws LogicException When the run is over.
     */
    public function submission(Form | string $form, array $data, string $locale, CarbonInterface $createdAt, ?CarbonInterface $readAt = null, ?Entry $entry = null): Submission
    {
        $this->refuseOver();
        $handle = $form instanceof Form ? $form->handle() : $form;
        $created = $this->utc($createdAt);

        try {
            $form = $form instanceof Form ? $form : resolve(Kami::class)->form($form);

            if (! in_array($locale, Site::current()->locales, true)) {
                throw new InvalidArgumentException("the Locale [{$locale}] is not one of the Site's.");
            }

            // Through the model's cast, as the json column gives it back: an object or a float such as 2.0 then compares equal on a replay.
            $data = new Submission(['data' => array_intersect_key($data, array_flip(Forms::fields($form)))])->data;
            $read = $this->utc($readAt);

            // Compared in PHP, not in SQL: JSON equality differs by engine, and MySQL reorders keys.
            $stored = Submission::query()->where(['site' => $this->site, 'form' => $handle, 'locale' => $locale, 'created_at' => $created])->get()
                ->first(fn (Submission $submission): bool => self::sorted($submission->data) === self::sorted($data));

            if ($stored instanceof Submission) {
                $this->unchanged++;

                return $stored;
            }

            // Read later than it was sent, as `markRead()` moves `updated_at`.
            $submission = $this->audited(fn (): Submission => Submission::query()->create(['site' => $this->site, 'locale' => $locale, 'form' => $handle, 'entry_id' => $entry?->id, 'data' => $data, 'read_at' => $read, 'created_at' => $created, 'updated_at' => $read ?? $created]));
        } catch (Throwable $exception) {
            $this->fail("a [{$handle}] Submission in [{$locale}] created at [{$created}]", $exception);
        }

        $this->created++;

        return $submission;
    }

    /**
     * The Entry imported under this Legacy id, by this run or an earlier one; null when there is none or it is in the Trash, which a relation Field refuses as a target.
     */
    public function find(string $source, string $legacyId): ?Entry
    {
        $id = DB::table('kami_import_keys')->where(['site' => $this->site, 'source' => $source, 'legacy_id' => $legacyId])->value('entry_id');

        return is_string($id) ? Entry::query()->withUnpublished()->find($id) : null;
    }

    /**
     * Closes the run: records one `kami.import.completed` Audit event with the label and the counts, and reports them.
     *
     * @throws LogicException When the run is over: an item failed, or it finished already.
     */
    public function finish(): ImportReport
    {
        $this->refuseOver();
        $this->over = true;

        /** @var list<array{source: string, legacy_id: string, entry_id: string|null, media_id: string|null}> $unseen */
        $unseen = DB::table('kami_import_keys')
            ->where('site', $this->site)
            ->whereIn('source', array_keys($this->sources))
            ->where('seen_at', '<', $this->startedAt)
            ->orderBy('source')
            ->orderBy('legacy_id')
            ->get(['source', 'legacy_id', 'entry_id', 'media_id'])
            ->map(fn (object $key): array => (array) $key)
            ->all();

        $report = new ImportReport($this->created, $this->updated, $this->unchanged, $unseen);

        $this->summarise('kami.import.completed', ['unseen' => $report->unseen]);

        return $report;
    }

    /**
     * Stops the run on an importer's own exception, between items: records one `kami.import.failed` Audit event with the label and the counts of what it committed, then throws the exception again. A run an item already stopped records nothing more.
     *
     * @throws Throwable The exception given.
     */
    public function abort(Throwable $exception): never
    {
        if (! $this->over) {
            $this->over = true;
            $this->summarise('kami.import.failed');
        }

        throw $exception;
    }

    /**
     * Skips the item when its payload's checksum is the stored one, else writes it and its key in one transaction.
     *
     * @param  'entry_id'|'media_id'  $column  The key's target: exactly one is set, which SQLite does not check.
     * @param  array<mixed>  $payload  What the importer handed over; a change in it rewrites the item.
     * @param  Closure(string|null): string  $write  Writes the item, given the id of the one imported earlier, and returns its id.
     *
     * @throws RuntimeException
     */
    private function item(string $source, string $legacyId, string $column, array $payload, Closure $write): string
    {
        $this->sources[$source] = true;

        try {
            // A Document's properties are private: as given, every one would hash as `{}`.
            array_walk_recursive($payload, function (mixed &$value): void {
                $value = $value instanceof Document ? $value->content() : $value;
            });
            $checksum = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            $key = DB::table('kami_import_keys')->where(['site' => $this->site, 'source' => $source, 'legacy_id' => $legacyId])->first();

            // An Entry's key handed to `media()`, or the reverse: SQLite has no check to refuse both columns set.
            if ($key?->{$column === 'entry_id' ? 'media_id' : 'entry_id'} !== null) {
                throw new LogicException('its key holds another kind of item.');
            }

            $id = is_string($key?->{$column}) ? $key->{$column} : null;

            if ($id !== null && $key->checksum === $checksum) {
                DB::table('kami_import_keys')->where('id', $key->id)->update(['seen_at' => now()]);
                $this->unchanged++;

                return $id;
            }

            $id = $this->audited(fn (): string => DB::transaction(function () use ($write, $id, $key, $column, $source, $legacyId, $checksum): string {
                $id = $write($id);
                $row = [$column => $id, 'checksum' => $checksum, 'seen_at' => now(), 'updated_at' => now()];

                if ($key === null) {
                    DB::table('kami_import_keys')->insert(['id' => (string) Str::ulid(), 'site' => $this->site, 'source' => $source, 'legacy_id' => $legacyId, 'created_at' => now(), ...$row]);
                } else {
                    DB::table('kami_import_keys')->where('id', $key->id)->update($row);
                }

                return $id;
            }));
        } catch (Throwable $exception) {
            $this->fail("[{$source}] item [{$legacyId}]", $exception);
        }

        $key === null ? $this->created++ : $this->updated++;

        return $id;
    }

    /** The Entry `kami:install` created for a Singleton or a Global Set, adopted by its first import; null for any other type. */
    private function installedEntry(string $type): ?Entry
    {
        $contentType = resolve(ContentTypes::class)->find($type);

        return $contentType instanceof Singleton
            ? Entry::query()->withUnpublished()->ofType($contentType->handle())->where('site', $this->site)->first()
            : null;
    }

    /**
     * Runs one write in its own Audit batch and adds its counts to the run's: no batch stays open between calls, so an importer's own exception leaves none behind.
     *
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     */
    private function audited(Closure $write): mixed
    {
        $batch = AuditContext::open();

        try {
            return $write();
        } finally {
            foreach ($batch->close() as $event => $count) {
                $this->events[$event] = ($this->events[$event] ?? 0) + $count;
            }
        }
    }

    /** @param  array<string, scalar>  $context */
    private function summarise(string $event, array $context = []): void
    {
        resolve(RecordAuditEvent::class)->summarise($event, ['label' => $this->label, 'created' => $this->created, 'updated' => $this->updated, 'unchanged' => $this->unchanged, ...$context, ...$this->events]);
    }

    /**
     * Stops the run, recording one `kami.import.failed` Audit event with the label, the counts of what it committed and the item.
     *
     * @throws RuntimeException
     */
    private function fail(string $item, Throwable $exception): never
    {
        $this->over = true;

        try {
            $this->summarise('kami.import.failed', ['item' => $item]);
        } finally {
            // Thrown even when the summary cannot be written, the database gone for instance: the item's name matters more.
            // Code 0, not the cause's: a QueryException's is a SQLSTATE string, which Rector would pass on.
            throw new RuntimeException("The import of {$item} failed: {$exception->getMessage()}", 0, $exception);
        }
    }

    /** @return array<'created_at'|'updated_at', string> The dates given, as UTC strings: hashed alike on every run. */
    private function dates(?CarbonInterface $createdAt, ?CarbonInterface $updatedAt): array
    {
        return array_filter(['created_at' => $this->utc($createdAt), 'updated_at' => $this->utc($updatedAt)]);
    }

    /** @return ($date is null ? null : string) As stored, in UTC to the second. */
    private function utc(?CarbonInterface $date): ?string
    {
        return $date?->toImmutable()->utc()->format('Y-m-d H:i:s');
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed> With its keys sorted at every level.
     */
    private static function sorted(array $data): array
    {
        ksort($data);

        return array_map(fn (mixed $value): mixed => is_array($value) ? self::sorted($value) : $value, $data);
    }

    /** @throws LogicException */
    private function refuseOver(): void
    {
        if ($this->over) {
            throw new LogicException('This Import is over: an item failed, or it finished. Start another run.');
        }
    }
}
