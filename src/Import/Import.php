<?php

declare(strict_types=1);

namespace Psyao\Kami\Import;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Psyao\Kami\Audit\Actions\RecordAuditEvent;
use Psyao\Kami\Audit\AuditContext;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
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

    private function __construct(
        private readonly string $label,
        private readonly string $site,
        private readonly CarbonImmutable $startedAt,
        private readonly AuditContext $audit,
    ) {}

    /**
     * Opens a run into a Site, the current one when `$site` is null. Until `finish()`, writes record no Audit event of their own: the run records one summary.
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
        return new self($label, $current, now()->toImmutable()->startOfSecond(), AuditContext::open());
    }

    /**
     * Imports one source item as an Entry, through `SaveEntry`, with its original dates: in one transaction, whole or not at all. An item whose payload, the arguments here, did not change since its last import is skipped.
     *
     * @param  string  $source  The system and its id space, such as `wordpress.post`.
     * @param  string  $legacyId  The item's id in the source.
     * @param  class-string<ContentType>|string  $type  The Content Type, by class or handle.
     * @param  array<string, array<string, mixed>>  $values  The values `SaveEntry` takes, by Locale, the default Locale first. Shared Fields go in any of them. An untranslatable type takes one, under any key.
     * @param  CarbonInterface|null  $createdAt  Set on the Entry and its Translations; the time of the first import when null.
     * @param  CarbonInterface|null  $updatedAt  Set on the Entry and its Translations; the time of the write when null.
     *
     * @throws RuntimeException When the item fails, naming the source and the Legacy id: the run stops there, and the item leaves nothing behind.
     */
    public function entry(string $source, string $legacyId, string $type, array $values, ?CarbonInterface $createdAt = null, ?CarbonInterface $updatedAt = null): Entry
    {
        $dates = array_filter(['created_at' => $createdAt?->toImmutable()->utc()->format('Y-m-d H:i:s'), 'updated_at' => $updatedAt?->toImmutable()->utc()->format('Y-m-d H:i:s')]);

        $id = $this->item($source, $legacyId, 'entry_id', [$type, $values, $dates], function (?string $id) use ($type, $values, $dates): string {
            $save = resolve(SaveEntry::class);
            $entry = $id === null ? null : Entry::query()->withUnpublished()->withTrashed()->findOrFail($id);

            foreach ($values as $locale => $localeValues) {
                $entry = $save->handle($type, $localeValues, $entry, (string) $locale);
            }

            if (! $entry instanceof Entry) {
                throw new InvalidArgumentException('An item holds the values of one Locale at least.');
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
     * The Entry imported under this Legacy id, by this run or an earlier one; null when there is none.
     */
    public function find(string $source, string $legacyId): ?Entry
    {
        $id = DB::table('kami_import_keys')->where(['site' => $this->site, 'source' => $source, 'legacy_id' => $legacyId])->value('entry_id');

        return is_string($id) ? Entry::query()->withUnpublished()->withTrashed()->find($id) : null;
    }

    /**
     * Closes the run: records one `kami.import.completed` Audit event with the label and the counts, and reports them.
     */
    public function finish(): ImportReport
    {
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

        resolve(RecordAuditEvent::class)->summarise('kami.import.completed', [
            'label' => $this->label,
            'created' => $report->created,
            'updated' => $report->updated,
            'unchanged' => $report->unchanged,
            'unseen' => $report->unseen,
            ...$this->audit->close(),
        ]);

        return $report;
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
        $checksum = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $key = DB::table('kami_import_keys')->where(['site' => $this->site, 'source' => $source, 'legacy_id' => $legacyId])->first();
        $id = is_string($key?->{$column}) ? $key->{$column} : null;

        if ($id !== null && $key->checksum === $checksum) {
            DB::table('kami_import_keys')->where('id', $key->id)->update(['seen_at' => now()]);
            $this->unchanged++;

            return $id;
        }

        try {
            $id = DB::transaction(function () use ($write, $id, $key, $column, $source, $legacyId, $checksum): string {
                $id = $write($id);
                $row = [$column => $id, 'checksum' => $checksum, 'seen_at' => now(), 'updated_at' => now()];

                if ($key === null) {
                    DB::table('kami_import_keys')->insert(['id' => (string) Str::ulid(), 'site' => $this->site, 'source' => $source, 'legacy_id' => $legacyId, 'created_at' => now(), ...$row]);
                } else {
                    DB::table('kami_import_keys')->where('id', $key->id)->update($row);
                }

                return $id;
            });
        } catch (Throwable $exception) {
            // The run stops here: its writes record their own Audit events again.
            $this->audit->close();

            throw new RuntimeException("The import of [{$source}] item [{$legacyId}] failed: {$exception->getMessage()}", $exception->getCode(), previous: $exception);
        }

        $key === null ? $this->created++ : $this->updated++;

        return $id;
    }
}
