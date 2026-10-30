<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Events\EntryPublished;
use Psyao\Kami\Content\Events\EntrySaved;
use Psyao\Kami\Content\Events\EntryUnpublished;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Content\Internal\Translation;
use Psyao\Kami\Content\Internal\Visibility;
use Psyao\Kami\Routing\Internal\Path;
use Psyao\Kami\Sites\Internal\Site;

/**
 * Creates or updates one Translation of an Entry: the one way content is written, from the admin, seeders or imports alike. It does not authorise: the caller checks the actor's Permissions first (ADR 0011).
 *
 * Invalid input throws Laravel's `ValidationException`, with its errors on the values concerned.
 */
final class SaveEntry
{
    /**
     * @param  class-string<ContentType>|string  $type  The Content Type, by class or handle.
     * @param  array<string, mixed>  $values  `title`, `slug` (from the title when blank), `status` (`draft`, the default, or `published`), `published_at` (now when publishing without one) and the type's Field values. A value left out keeps its stored one; `''` and `[]` store `null`.
     * @param  Entry|null  $entry  The Entry to update; a new one when null.
     * @param  string|null  $locale  The Locale written, the default one when null. Shared Field values go to the Entry's `'*'` row whatever the Locale. `'*'` writes a translatable type's Shared Fields only; an untranslatable type always writes `'*'`.
     * @param  Authenticatable|null  $actor  Recorded as the Creator of a new Entry and the Last modifier; null for imports and the CLI.
     *
     * @throws ValidationException
     */
    public function handle(string $type, array $values, ?Entry $entry = null, ?string $locale = null, ?Authenticatable $actor = null): Entry
    {
        $contentType = resolve(ContentTypes::class)->find($type) ?? throw new InvalidArgumentException("No Content Type [{$type}] is declared in kami.types.");
        $site = Site::current();
        $locale = $contentType->isTranslatable() ? $locale ?? $site->defaultLocale() : '*';

        if ($locale !== '*' && ! in_array($locale, $site->locales, true)) {
            throw new InvalidArgumentException("No Locale [{$locale}] is declared in Site [{$site->handle}].");
        }

        // A translatable type's `'*'` call holds no Translation: only its Shared Fields.
        $writesTranslation = $locale !== '*' || ! $contentType->isTranslatable();
        $values = array_map(fn (mixed $value): mixed => $value === '' || $value === [] ? null : $value, $values);
        $fields = [];

        foreach ($contentType->fields() as $field) {
            if ($writesTranslation || ! $contentType->isTranslatable($field)) {
                $fields[$field->handle] = $field;
            }
        }

        $outOfScope = array_keys(Arr::except($values, [...array_keys($fields), ...($writesTranslation ? Translation::ATTRIBUTES : [])]));

        // Laravel's own `prohibited` message, translated and named as the host's other errors are.
        Validator::make(array_fill_keys($outOfScope, true), array_fill_keys($outOfScope, 'prohibited'))->validate();

        $translation = $entry instanceof Entry ? Translation::query()->firstOrNew(['entry_id' => $entry->id, 'locale' => $locale]) : new Translation(['locale' => $locale]);
        $stored = $entry instanceof Entry ? DB::table("kami_type_{$contentType->handle()}")->where('entry_id', $entry->id)->whereIn('locale', [$locale, '*'])->get()->keyBy('locale') : collect();

        // A new Translation starts from its Fields' defaults; a Shared Field's only while the Entry has no `'*'` row yet.
        if ($writesTranslation && ! $translation->exists) {
            foreach ($fields as $handle => $field) {
                if (! array_key_exists($handle, $values) && $field->defaultValue() !== null && ($contentType->isTranslatable($field) || ! $stored->has('*'))) {
                    $values[$handle] = $field->defaultValue();
                }
            }
        }

        // The Translation as it will be once saved, stored values under the given ones, validated whole.
        $data = $writesTranslation ? [...Arr::only($translation->getAttributes(), Translation::ATTRIBUTES), 'status' => $translation->status ?? Translation::DRAFT] : [];
        foreach ($fields as $handle => $field) {
            $data[$handle] = $stored->get($contentType->isTranslatable($field) ? $locale : '*')?->{$handle};
        }

        $data = [...$data, ...$values];

        if ($writesTranslation) {
            $data['slug'] ??= is_string($data['title'] ?? null) ? Path::slugFrom($data['title'], $locale === '*' ? $site->defaultLocale() : $locale) : null;
            $data['slug'] = is_string($data['slug']) ? Path::normalise($data['slug']) : $data['slug'];
            $data['slug'] = $data['slug'] === '' ? null : $data['slug'];
        }

        $publishing = ($data['status'] ?? null) === Translation::PUBLISHED;
        $rules = $writesTranslation ? [
            'title' => [$publishing ? 'required' : 'nullable', 'string', 'max:255'],
            'slug' => [$publishing ? 'required' : 'nullable', 'string', 'max:255'],
            'status' => ['required', 'in:draft,published'],
            'published_at' => ['nullable', 'date'],
        ] : [];

        foreach ($fields as $handle => $field) {
            $rules[$handle] = [$publishing && $field->isRequired() ? 'required' : 'nullable', ...$field->validationRules()];
        }

        Validator::make($data, $rules)->validate();

        $wasPublished = $translation->status === Translation::PUBLISHED;
        $wasVisible = Visibility::isVisible($translation);

        $id = DB::transaction(function () use ($contentType, $site, $locale, $values, $entry, $actor, $writesTranslation, $translation, $data, $fields): string {
            $entry ??= (new Entry)->forceFill(['site' => $site->handle, 'type' => $contentType->handle(), 'created_by' => $actor?->getAuthIdentifier()]);
            $entry->forceFill(['updated_by' => $actor?->getAuthIdentifier()])->save();

            if ($writesTranslation) {
                $translation->entry_id = $entry->id;
                foreach (Translation::ATTRIBUTES as $attribute) {
                    $translation->setAttribute($attribute, $data[$attribute] ?? null);
                }

                // ADR 0019: publishing with no date publishes now; unpublishing keeps the date, which republishing reuses.
                if ($translation->status === Translation::PUBLISHED) {
                    $translation->published_at ??= now()->toImmutable();
                }

                $translation->save();
            }

            $rows = [];
            foreach ($fields as $handle => $field) {
                if (array_key_exists($handle, $values)) {
                    $rows[$contentType->isTranslatable($field) ? $locale : '*'][$handle] = $field->write($values[$handle]);
                }
            }

            foreach ($rows as $rowLocale => $row) {
                DB::table("kami_type_{$contentType->handle()}")->updateOrInsert(['entry_id' => $entry->id, 'locale' => $rowLocale], $row);
            }

            if ($writesTranslation) {
                $this->reservePath($contentType, $site, $entry, $locale, $translation->slug);
            }

            return $entry->id;
        });

        $saved = Entry::query()->withUnpublished()->findOrFail($id);

        // After commit, so a listener never sees a write rolled back; a caller's own transaction defers them to its commit (ADR 0029).
        event(new EntrySaved($saved, $locale, ! $entry instanceof Entry, $actor));

        if (! $wasVisible && Visibility::isVisible($translation)) {
            event(new EntryPublished($saved, $locale, false, $actor));
        } elseif ($wasPublished && ($data['status'] ?? null) === Translation::DRAFT) {
            event(new EntryUnpublished($saved, $locale, $actor));
        }

        return $saved;
    }

    /** The Translation's 200 row, rewritten in place when its slug changes; the `'*'` Translation answers in every Locale of the Site, one row each (ADR 0008). */
    private function reservePath(ContentType $contentType, Site $site, Entry $entry, string $locale, ?string $slug): void
    {
        $locales = $locale === '*' ? $site->locales : [$locale];

        if ($slug === null) {
            Path::query()->where(['site' => $site->handle, 'entry_id' => $entry->id, 'status' => 200])->whereIn('locale', $locales)->delete();

            return;
        }

        try {
            foreach ($locales as $pathLocale) {
                Path::query()->updateOrCreate(
                    ['site' => $site->handle, 'locale' => $pathLocale, 'entry_id' => $entry->id, 'status' => 200],
                    ['path' => Path::of($contentType, $slug)],
                );
            }
        } catch (UniqueConstraintViolationException) {
            // Caught from the unique index, so two concurrent saves cannot both pass a prior lookup.
            throw ValidationException::withMessages(['slug' => __('validation.unique', ['attribute' => 'slug'])]);
        }
    }
}
