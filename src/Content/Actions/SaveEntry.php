<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Actions;

use Closure;
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
use Psyao\Kami\Content\Fields\Field;
use Psyao\Kami\Content\Fields\RelationField;
use Psyao\Kami\Content\Fields\StructuredField;
use Psyao\Kami\Content\GlobalSet;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Content\Internal\References;
use Psyao\Kami\Content\Internal\Translation;
use Psyao\Kami\Content\Internal\Visibility;
use Psyao\Kami\Content\Singleton;
use Psyao\Kami\Content\Taxonomy;
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
     * @param  array<string, mixed>  $values  `title` (the type's label on a Global Set when blank), `slug` (from the title when blank; refused by a Singleton or a type with no URL), `status` (`draft`, the default, or `published`, a Taxonomy's default; refused by a Global Set, always published), `published_at` (now when publishing without one; refused by a Global Set) and the type's Field values. A value left out keeps its stored one; `''` and `[]` store `null`.
     * @param  Entry|null  $entry  The Entry to update; a new one when null.
     * @param  string|null  $locale  The Locale written, the default one when null. Shared Field values go to the Entry's `'*'` row whatever the Locale. `'*'` writes a translatable type's Shared Fields only; an untranslatable type always writes `'*'`.
     * @param  Authenticatable|null  $actor  Recorded as the Creator of a new Entry and the Last modifier; null for imports and the CLI.
     *
     * @throws ValidationException
     */
    public function handle(string $type, array $values, ?Entry $entry = null, ?string $locale = null, ?Authenticatable $actor = null): Entry
    {
        $contentType = resolve(ContentTypes::class)->find($type) ?? throw new InvalidArgumentException("No Content Type [{$type}] is declared in kami.types.");

        if ($entry instanceof Entry && $entry->type !== $contentType->handle()) {
            throw new InvalidArgumentException("The Entry [{$entry->id}] is of type [{$entry->type}], not [{$contentType->handle()}].");
        }

        $site = Site::current();
        $locale = $contentType->isTranslatable() ? $locale ?? $site->defaultLocale() : '*';

        if ($locale !== '*' && ! in_array($locale, $site->locales, true)) {
            throw new InvalidArgumentException("No Locale [{$locale}] is declared in Site [{$site->handle}].");
        }

        // ponytail: no database constraint, as the portable partial unique index does not exist; two concurrent first saves can both pass. The admin offers no create page, so only site code or a concurrent `kami:install` can race; lifted if a friction shows it.
        if (! $entry instanceof Entry && $contentType instanceof Singleton && Entry::query()->withUnpublished()->ofType($contentType->handle())->where('site', $site->handle)->exists()) {
            throw ValidationException::withMessages(['type' => __('validation.unique', ['attribute' => $contentType->label()])]);
        }

        // A translatable type's `'*'` call holds no Translation: only its Shared Fields.
        $writesTranslation = $locale !== '*' || ! $contentType->isTranslatable();
        $values = array_map(fn (mixed $value): mixed => $value === '' || $value === [] ? null : $value, $values);
        $fields = [];

        foreach ($contentType->flatFields() as $field) {
            if ($writesTranslation || ! $contentType->isTranslatable($field)) {
                $fields[$field->handle] = $field;
            }
        }

        $attributes = match (true) {
            $contentType instanceof GlobalSet => ['title'],
            $contentType->hasSlug() => Translation::ATTRIBUTES,
            default => array_diff(Translation::ATTRIBUTES, ['slug']),
        };
        $outOfScope = array_keys(Arr::except($values, [...array_keys($fields), ...($writesTranslation ? $attributes : [])]));

        // Laravel's own `prohibited` message, translated and named as the host's other errors are.
        Validator::make(array_fill_keys($outOfScope, true), array_fill_keys($outOfScope, 'prohibited'))->validate();

        $translation = $entry instanceof Entry ? Translation::query()->firstOrNew(['entry_id' => $entry->id, 'locale' => $locale]) : new Translation(['locale' => $locale]);
        // A type with no column has no table.
        $stored = $entry instanceof Entry && $contentType->columnFields() !== [] ? DB::table("kami_type_{$contentType->handle()}")->where('entry_id', $entry->id)->whereIn('locale', [$locale, '*'])->get()->keyBy('locale') : collect();

        $relations = $contentType->relationFields();
        $references = $entry instanceof Entry && $relations !== [] ? References::stored([$entry->id], array_column($relations, 'handle'))[$entry->id] ?? [] : [];

        // A new Translation starts from its Fields' defaults; a Shared Field's only while the Entry has no `'*'` row yet.
        if ($writesTranslation && ! $translation->exists) {
            foreach ($fields as $handle => $field) {
                if (! array_key_exists($handle, $values) && $field->defaultValue() !== null && ($contentType->isTranslatable($field) || ! $stored->has('*'))) {
                    $values[$handle] = $field->defaultValue();
                }
            }
        }

        foreach (array_intersect_key($fields, $values) as $handle => $field) {
            $values[$handle] = $field->normalise($values[$handle]);
        }

        // The Translation as it will be once saved, stored values under the given ones, validated whole.
        $data = $writesTranslation ? [...Arr::only($translation->getAttributes(), Translation::ATTRIBUTES), 'status' => $translation->status ?? ($contentType instanceof Taxonomy ? Translation::PUBLISHED : Translation::DRAFT)] : [];
        foreach ($fields as $handle => $field) {
            $scope = $contentType->isTranslatable($field) ? $locale : '*';

            if ($field instanceof RelationField) {
                $data[$handle] = $references[$scope][$handle] ?? null;

                continue;
            }

            $column = $stored->get($scope)?->{$handle};
            $data[$handle] = $field instanceof StructuredField ? $field->decoded($column) : $column;
        }

        $data = [...$data, ...$values];

        // Always published, with nothing for an editor to fill: its form shows Fields only.
        if ($writesTranslation && $contentType instanceof GlobalSet) {
            $data['status'] = Translation::PUBLISHED;
            $data['title'] ??= $contentType->label();
        }

        if ($writesTranslation && $contentType->hasSlug()) {
            $data['slug'] ??= is_string($data['title'] ?? null) ? Path::slugFrom($data['title'], $locale === '*' ? $site->defaultLocale() : $locale) : null;
            $data['slug'] = is_string($data['slug']) ? Path::normalise($data['slug']) : $data['slug'];
            $data['slug'] = $data['slug'] === '' ? null : $data['slug'];
        }

        $publishing = ($data['status'] ?? null) === Translation::PUBLISHED;
        $rules = $writesTranslation ? [
            // A Singleton's form has no slug: what its Prefix breaks shows on the title.
            'title' => [$publishing ? 'required' : 'nullable', 'string', 'max:255', ...($contentType->hasSlug() ? [] : [$this->pathRule($contentType, $site, $locale)])],
            ...($contentType->hasSlug() ? ['slug' => [$publishing ? 'required' : 'nullable', 'string', 'max:255', $this->pathRule($contentType, $site, $locale)]] : []),
            'status' => ['required', 'in:draft,published'],
            'published_at' => ['nullable', 'date'],
        ] : [];

        foreach ($fields as $handle => $field) {
            $rules = [...$rules, ...$field->rulesAt($handle, $publishing)];
        }

        // Conditions read their targets through a cast, which an invalid value could break: the targets pass first.
        $conditional = array_filter($contentType->flatFields(), fn (Field $field): bool => $field->conditionTarget() !== null);
        Validator::make($data, Arr::only($rules, array_map(fn (Field $field): ?string => $field->conditionTarget(), $conditional)))->validate();

        $cleared = $clearedReferences = [];

        foreach ($conditional as $field) {
            // A target is Shared, so in scope of every call, and hides its Field in every Locale.
            $target = $fields[(string) $field->conditionTarget()];

            if (! $field->isVisibleGiven($target, $data[$target->handle])) {
                unset($values[$field->handle]);
                $rules = array_diff_key($rules, $field->rulesAt($field->handle, $publishing));

                if ($field instanceof RelationField) {
                    $clearedReferences[] = $field->handle;
                } else {
                    $cleared[$field->handle] = null;
                }
            }
        }

        Validator::make($data, $rules)->validate();

        $wasPublished = $translation->status === Translation::PUBLISHED;
        $wasVisible = Visibility::isVisible($translation);

        $id = DB::transaction(function () use ($contentType, $site, $locale, $values, $entry, $actor, $writesTranslation, $translation, $data, $fields, $cleared, $clearedReferences): string {
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
                if (! array_key_exists($handle, $values)) {
                    continue;
                }

                if ($field instanceof RelationField) {
                    /** @var list<string>|null $targets */
                    $targets = $values[$handle];
                    References::replace($entry->id, $handle, $contentType->isTranslatable($field) ? $locale : null, $targets ?? []);
                } else {
                    $rows[$contentType->isTranslatable($field) ? $locale : '*'][$handle] = $field->write($values[$handle]);
                }
            }

            foreach ($rows as $rowLocale => $row) {
                DB::table("kami_type_{$contentType->handle()}")->updateOrInsert(['entry_id' => $entry->id, 'locale' => $rowLocale], $row);
            }

            if ($cleared !== []) {
                DB::table("kami_type_{$contentType->handle()}")->where('entry_id', $entry->id)->update($cleared);
            }

            foreach ($clearedReferences as $handle) {
                References::clear($entry->id, $handle);
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

    /**
     * The Path's characters, then the default Locale's Paths, which `ResolveLocale` hides behind another Locale's code as first segment.
     *
     * ponytail: at save only, so a Locale or Prefix declared later can still hide a stored Path; M7's reserved paths lift it.
     */
    private function pathRule(ContentType $contentType, Site $site, string $locale): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($contentType, $site, $locale): void {
            $path = is_string($value) ? Path::of($contentType, $value) : null;

            if ($path === null) {
                return;
            }

            if (! Path::isAnswerable($path)) {
                $fail(__('validation.regex'));
            } elseif (in_array($locale, [$site->defaultLocale(), '*'], true) && in_array(explode('/', $path)[0], array_slice($site->locales, 1), true)) {
                $fail(__('validation.not_in'));
            }
        };
    }

    /** The Translation's 200 row, rewritten in place when its slug changes; the `'*'` Translation answers in every Locale of the Site, one row each (ADR 0008). */
    private function reservePath(ContentType $contentType, Site $site, Entry $entry, string $locale, ?string $slug): void
    {
        $locales = $locale === '*' ? $site->locales : [$locale];
        $path = Path::of($contentType, $slug);

        if ($path === null) {
            Path::query()->where(['site' => $site->handle, 'entry_id' => $entry->id, 'status' => 200])->whereIn('locale', $locales)->delete();

            return;
        }

        try {
            foreach ($locales as $pathLocale) {
                Path::query()->updateOrCreate(
                    ['site' => $site->handle, 'locale' => $pathLocale, 'entry_id' => $entry->id, 'status' => 200],
                    ['path' => $path],
                );
            }
        } catch (UniqueConstraintViolationException) {
            // Caught from the unique index, so two concurrent saves cannot both pass a prior lookup.
            throw ValidationException::withMessages($contentType->hasSlug() ? ['slug' => __('validation.unique', ['attribute' => 'slug'])] : ['title' => __('kami::admin.fields.path_taken')]);
        }
    }
}
