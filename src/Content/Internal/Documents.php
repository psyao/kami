<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Fields\Field;
use Psyao\Kami\Content\Fields\RichText;

/**
 * The RichText documents a type stores, read Entry batch by Entry batch: what the command line scans or rewrites whole, never a request.
 *
 * @internal
 */
final class Documents
{
    /** Entries a batch reads: a few documents each, one per Locale and RichText Field, within shared hosting's memory. */
    public const int BATCH = 500;

    /**
     * Each batch of a type's documents, Entries in id order, each with its own `content`. A Field whose column is missing yet, a blank one and a type with no table hold none.
     *
     * @param  Closure(list<array{entry: string, locale: string, field: RichText, document: array<mixed>, content: array<mixed>}>): void  $batch
     */
    public static function each(ContentType $type, Closure $batch, ?string $site = null): void
    {
        $table = "kami_type_{$type->handle()}";
        $columns = Schema::hasTable($table) ? Schema::getColumnListing($table) : [];
        $fields = array_filter($type->columnFields(), fn (Field $field): bool => $field instanceof RichText && in_array($field->handle, $columns, true));

        if ($fields === []) {
            return;
        }

        DB::table('kami_entries')->where('type', $type->handle())->when($site !== null, fn ($query) => $query->where('site', $site))->select('id')
            ->chunkById(self::BATCH, function (Collection $entries) use ($table, $fields, $batch): void {
                $documents = [];

                foreach (DB::table($table)->whereIn('entry_id', $entries->pluck('id')->all())->orderBy('entry_id')->orderBy('locale')->get(['entry_id', 'locale', ...array_map(fn (Field $field): string => $field->handle, $fields)]) as $row) {
                    /** @var object{entry_id: string, locale: string} $row */
                    [$entry, $locale] = [$row->entry_id, $row->locale];

                    foreach ($fields as $field) {
                        $document = $field->decoded($row->{$field->handle});

                        if ($document !== null && is_array($content = $document['content'] ?? null)) {
                            $documents[] = ['entry' => $entry, 'locale' => $locale, 'field' => $field, 'document' => $document, 'content' => $content];
                        }
                    }
                }

                $batch($documents);
            });
    }
}
