<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Actions;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Content\Internal\Translation;
use Psyao\Kami\Routing\Internal\Path;
use Psyao\Kami\Sites\Internal\Site;

/** Creates or updates one Translation of an Entry: the one way content is written, from the admin, seeders or imports alike. It does not authorise (ADR 0011). */
final class SaveEntry
{
    /**
     * @param  class-string<ContentType>|string  $type  The Content Type, by class or handle.
     * @param  array<string, mixed>  $values  `title`, `slug` (from the title when missing), `status` (`draft`, the default, or `published`), `published_at` (now when publishing without one) and the type's Field values.
     * @param  Entry|null  $entry  The Entry to update; a new one when null.
     * @param  string|null  $locale  The Locale written; when null, the default Locale, or `'*'` for an untranslatable type. Shared Field values go to the Entry's `'*'` row whatever the Locale.
     */
    public function handle(string $type, array $values, ?Entry $entry = null, ?string $locale = null): Entry
    {
        $contentType = resolve(ContentTypes::class)->find($type) ?? throw new InvalidArgumentException("No Content Type [{$type}] is declared in kami.types.");
        $site = Site::current();
        $locale ??= $contentType->isTranslatable() ? $site->defaultLocale() : '*';

        // ponytail: the minimal write of #96; validation, blanks, the slug clash and the actor are #98's, events #99's.
        $id = DB::transaction(function () use ($contentType, $site, $locale, $values, $entry): string {
            $entry ??= (new Entry)->forceFill(['site' => $site->handle, 'type' => $contentType->handle()]);
            $entry->save();

            $translation = Translation::query()->firstOrNew(['entry_id' => $entry->id, 'locale' => $locale]);
            foreach (Arr::only($values, ['title', 'slug', 'status', 'published_at']) as $attribute => $value) {
                $translation->setAttribute($attribute, $value);
            }

            $translation->slug ??= Str::slug($translation->title);
            $translation->status ??= 'draft';

            if ($translation->status === 'published') {
                $translation->published_at ??= now()->toImmutable();
            }

            $translation->save();

            $rows = [];
            foreach ($contentType->fields() as $field) {
                if (array_key_exists($field->handle, $values)) {
                    $rows[$contentType->isTranslatable($field) ? $locale : '*'][$field->handle] = $values[$field->handle];
                }
            }

            foreach ($rows as $rowLocale => $fields) {
                DB::table("kami_type_{$contentType->handle()}")->updateOrInsert(['entry_id' => $entry->id, 'locale' => $rowLocale], $fields);
            }

            // The `'*'` Translation answers in every Locale of the Site, one row each (ADR 0008).
            foreach ($locale === '*' ? $site->locales : [$locale] as $pathLocale) {
                Path::query()->updateOrCreate(
                    ['site' => $site->handle, 'locale' => $pathLocale, 'entry_id' => $entry->id, 'status' => 200],
                    ['path' => Path::of($contentType, $translation->slug)],
                );
            }

            return $entry->id;
        });

        return Entry::query()->withUnpublished()->findOrFail($id);
    }
}
