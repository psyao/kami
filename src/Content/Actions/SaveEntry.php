<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Actions;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Psyao\Kami\Audit\Actions\RecordAuditEvent;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Events\EntryPublished;
use Psyao\Kami\Content\Events\EntrySaved;
use Psyao\Kami\Content\Events\EntryUnpublished;
use Psyao\Kami\Content\Fields\Boolean;
use Psyao\Kami\Content\Fields\Field;
use Psyao\Kami\Content\Fields\RelationField;
use Psyao\Kami\Content\Fields\RichText;
use Psyao\Kami\Content\Fields\StructuredField;
use Psyao\Kami\Content\Fields\Text;
use Psyao\Kami\Content\Fields\Textarea;
use Psyao\Kami\Content\GlobalSet;
use Psyao\Kami\Content\Internal\Ancestors;
use Psyao\Kami\Content\Internal\AnnouncePublicationsCommand;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Content\Internal\References;
use Psyao\Kami\Content\Internal\ReorderEntries;
use Psyao\Kami\Content\Internal\Translation;
use Psyao\Kami\Content\Internal\Visibility;
use Psyao\Kami\Content\Singleton;
use Psyao\Kami\Content\Taxonomy;
use Psyao\Kami\Routing\Internal\Path;
use Psyao\Kami\Routing\Internal\PathTooLong;
use Psyao\Kami\Routing\Internal\SyncPaths;
use Psyao\Kami\Sites\Internal\Site;
use Psyao\Kami\Support\Internal\Install\PluginRegistration;

/**
 * Creates or updates one Translation of an Entry: the one way content is written, from the admin, seeders or imports alike. It does not authorise: the caller checks the actor's Permissions first (ADR 0011).
 *
 * Invalid input throws Laravel's `ValidationException`, with its errors on the values concerned.
 */
final class SaveEntry
{
    /**
     * @param  class-string<ContentType>|string  $type  The Content Type, by class or handle.
     * @param  array<string, mixed>  $values  `title` (the type's label on a Global Set when blank), `slug` (from the title when blank; refused by a Singleton or a type with no URL), `status` (`draft`, the default, or `published`, a Taxonomy's default; refused by a Global Set, always published), `published_at` (now when publishing without one; refused by a Global Set), `position` (last on a new Entry when blank; refused by a type that is not orderable; an Entry moved under another Parent goes last there when blank), `parent` (the id of another Entry of the type, `null` for the root; refused by a type that is not hierarchical) and the type's Field values. A value left out keeps its stored one; `''` and `[]` store `null`.
     * @param  Entry|null  $entry  The Entry to update; a new one when null.
     * @param  string|null  $locale  The Locale written, the default one when null. Shared Field values go to the Entry's `'*'` row whatever the Locale. `'*'` writes a translatable type's Shared Fields only, on an existing Entry; an untranslatable type always writes `'*'`.
     * @param  Authenticatable|null  $actor  Recorded as the Creator of a new Entry and the Last modifier; null for imports and the CLI.
     * @param  DateTimeInterface|null  $updatedAt  The Entry's `updated_at` as the caller loaded it: the save is refused, naming the Last modifier and the time, when the Entry changed since, in any Locale. Null checks nothing, for imports and the CLI.
     *
     * @throws ValidationException
     */
    public function handle(string $type, array $values, ?Entry $entry = null, ?string $locale = null, ?Authenticatable $actor = null, ?DateTimeInterface $updatedAt = null): Entry
    {
        [
            'contentType' => $contentType, 'site' => $site, 'locale' => $locale, 'values' => $values, 'writesTranslation' => $writesTranslation,
            'translation' => $translation, 'data' => $data, 'fields' => $fields, 'cleared' => $cleared, 'clearedReferences' => $clearedReferences,
        ] = $this->prepare($type, $values, $entry, $locale, $updatedAt, draft: false);

        $wasPublished = $translation->status === Translation::PUBLISHED;
        $wasVisible = Visibility::isVisible($translation);
        // Its moment came since `kami:announce-publications` last ran, which skips it once this save moves `updated_at` past `published_at`.
        $awaitsAnnouncement = $wasVisible && $translation->updated_at < $translation->published_at
            && $translation->published_at->getTimestamp() > (AnnouncePublicationsCommand::announcedUntil() ?? PHP_INT_MAX);
        $wasPublic = $translation->wasPublic();
        $publishedAt = $translation->published_at;

        $id = DB::transaction(function () use ($contentType, $site, $locale, $values, $entry, $actor, $writesTranslation, $translation, $data, $fields, $cleared, $clearedReferences, $wasPublished, $wasPublic, $publishedAt): string {
            $entry ??= (new Entry)->forceFill(['site' => $site->handle, 'type' => $contentType->handle(), 'created_by' => $actor?->getAuthIdentifier()]);
            // Touched by every save, one changing a Translation only too: the concurrent-edit check reads it.
            $entry->forceFill(['updated_by' => $actor?->getAuthIdentifier(), 'updated_at' => $entry->freshTimestamp()]);

            if (array_key_exists('parent', $values)) {
                $entry->forceFill(['parent_id' => $values['parent']]);
            }

            if ($contentType->isOrderable() && (isset($values['position']) || ! $entry->exists || $entry->isDirty('parent_id'))) {
                $entry->forceFill(['position' => $values['position'] ?? ReorderEntries::next($contentType, $entry->parent_id)]);
            }

            $entry->save();

            if ($writesTranslation) {
                $translation->entry_id = $entry->id;
                foreach (Translation::ATTRIBUTES as $attribute) {
                    $translation->setAttribute($attribute, $data[$attribute] ?? null);
                }

                // ADR 0019: publishing with no date publishes now; unpublishing keeps the date, which republishing reuses.
                if ($translation->status === Translation::PUBLISHED) {
                    $translation->published_at ??= now()->toImmutable();
                }

                // Read before the save: a date moved to the future no longer says the Translation was seen.
                $translation->was_public = $wasPublic;
                $translation->save();
            }

            $rows = [];
            foreach ($fields as $handle => $field) {
                if (! array_key_exists($handle, $values)) {
                    continue;
                }

                if ($field->isReference()) {
                    /** @var list<string>|null $targets */
                    $targets = $values[$handle];
                    References::replace($entry->id, $field, $contentType->isTranslatable($field) ? $locale : null, $targets ?? []);
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

            if ($writesTranslation || $entry->wasChanged('parent_id')) {
                // A descendant's Path holds this Entry's slug: it moves with a new Parent, a new slug or a first Translation in the Locale.
                $moves = $entry->wasChanged('parent_id') || ($writesTranslation && ($translation->wasRecentlyCreated || $translation->wasChanged('slug')));
                $this->reservePaths($contentType, $site, $entry, $locale, $writesTranslation ? $wasPublic : null, $moves);
            }

            if ($writesTranslation) {
                $this->recordVisibility($entry, $translation, $wasPublished, $publishedAt, $actor);
            }

            return $entry->id;
        });

        $saved = Entry::query()->withUnpublished()->findOrFail($id);
        // After commit, so a listener never sees a write rolled back; a caller's own transaction defers them to its commit (ADR 0029).
        event(new EntrySaved($saved, $locale, ! $entry instanceof Entry, $actor));

        if (! $wasVisible && Visibility::isVisible($translation)) {
            event(new EntryPublished($saved, $locale, false, $actor));
        } elseif ($awaitsAnnouncement && Visibility::isVisible($translation)) {
            event(new EntryPublished($saved, $locale, true, $actor));
        } elseif ($wasPublished && ($data['status'] ?? null) === Translation::DRAFT) {
            event(new EntryUnpublished($saved, $locale, $actor));
        }

        return $saved;
    }

    /**
     * @internal
     *
     * What `handle()` would write, checked with the Draft rules and written nowhere: the Preview's unsaved form (ADR 0019).
     *
     * @param  array<string, mixed>  $values
     * @return array{locale: string, data: array<mixed>} The Locale written and the Translation's attributes and Field values, a relation Field's as target ids.
     *
     * @throws ValidationException
     */
    public function draft(string $type, array $values, ?Entry $entry = null, ?string $locale = null): array
    {
        $prepared = $this->prepare($type, $values, $entry, $locale, null, draft: true);

        return ['locale' => $prepared['locale'], 'data' => [...$prepared['data'], ...$prepared['cleared'], ...array_fill_keys($prepared['clearedReferences'], null)]];
    }

    /**
     * Everything before the write: the values normalised, the Translation as it will be, validated whole; Draft rules require nothing.
     *
     * @param  array<string, mixed>  $values
     * @return array{contentType: ContentType, site: Site, locale: string, values: array<string, mixed>, writesTranslation: bool, translation: Translation, data: array<mixed>, fields: array<string, Field>, cleared: array<string, null>, clearedReferences: list<string>}
     *
     * @throws ValidationException
     */
    private function prepare(string $type, array $values, ?Entry $entry, ?string $locale, ?DateTimeInterface $updatedAt, bool $draft): array
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

        // ponytail: no database constraint, as the portable partial unique index does not exist; two concurrent first saves can both pass. The admin offers no create page, so only site code or a concurrent `kami:install` can race; V1 adds a lock if a friction shows it.
        if (! $entry instanceof Entry && $contentType instanceof Singleton && Entry::query()->withUnpublished()->ofType($contentType->handle())->where('site', $site->handle)->exists()) {
            throw ValidationException::withMessages(['type' => __('validation.unique', ['attribute' => $contentType->label()])]);
        }

        if ($entry?->trashed() === true) {
            throw new InvalidArgumentException("The Entry [{$entry->id}] is in the Trash: restore it first.");
        }

        if ($entry instanceof Entry && $updatedAt instanceof DateTimeInterface) {
            $this->refuseChangedSince($entry, $updatedAt, $site);
        }

        // A translatable type's `'*'` call holds no Translation: only its Shared Fields.
        $writesTranslation = $locale !== '*' || ! $contentType->isTranslatable();

        if (! $writesTranslation && ! $entry instanceof Entry) {
            throw new InvalidArgumentException("A '*' call on the translatable type [{$contentType->handle()}] writes Shared Fields only: it would create an Entry with no Translation.");
        }

        $values = array_map(fn (mixed $value): mixed => $value === '' || $value === [] ? null : $value, $values);
        $fields = [];

        foreach ($contentType->storedFields() as $field) {
            if ($writesTranslation || ! $contentType->isTranslatable($field)) {
                $fields[$field->handle] = $field;
            }
        }

        $attributes = $contentType instanceof GlobalSet ? ['title'] : array_diff(Translation::ATTRIBUTES, [
            ...($contentType->hasSlug() ? [] : ['slug']),
            ...($contentType->isRoutable() ? [] : Translation::SEO),
        ]);
        $outOfScope = array_keys(Arr::except($values, [...array_keys($fields), ...($writesTranslation ? $attributes : []), ...($contentType->isOrderable() ? ['position'] : []), ...($contentType->isHierarchical() ? ['parent'] : [])]));

        // Laravel's own `prohibited` message, translated and named as the host's other errors are.
        Validator::make(array_fill_keys($outOfScope, true), array_fill_keys($outOfScope, 'prohibited'))->validate();

        $translation = $entry instanceof Entry ? Translation::query()->firstOrNew(['entry_id' => $entry->id, 'locale' => $locale]) : new Translation(['locale' => $locale]);
        // A type with no column has no table.
        $stored = $entry instanceof Entry && $contentType->columnFields() !== [] ? DB::table("kami_type_{$contentType->handle()}")->where('entry_id', $entry->id)->whereIn('locale', [$locale, '*'])->get()->keyBy('locale') : collect();

        $relations = $contentType->relationFields();
        $references = $entry instanceof Entry && $relations !== [] ? References::stored([$entry->id], array_column($relations, 'handle'))[$entry->id] ?? [] : [];
        // Links to trashed targets read as absent: validated without them, kept by the write below.
        /** @var list<string> $trashed */
        $trashed = $references === [] ? [] : DB::table('kami_entries')->whereIn('id', Arr::flatten($references))->whereNotNull('deleted_at')->pluck('id')->all();

        // A new Translation starts from its Fields' defaults; a Shared Field's only with a new Entry: on an existing one it would reach Published Translations.
        if ($writesTranslation && ! $translation->exists) {
            foreach ($fields as $handle => $field) {
                if (! array_key_exists($handle, $values) && $field->defaultValue() !== null && ($contentType->isTranslatable($field) || ! $entry instanceof Entry)) {
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

            if ($field->isReference()) {
                $data[$handle] = array_values(array_diff($references[$scope][$handle] ?? [], $trashed)) ?: null;

                continue;
            }

            $column = $stored->get($scope)?->{$handle};
            $data[$handle] = $field instanceof StructuredField || $field instanceof RichText ? $field->decoded($column) : $column;
        }

        $before = $data;
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

        if ($writesTranslation) {
            // Its column takes no null: a blank or a type with no URL stores "not hidden".
            $data['noindex'] ??= false;
        }

        $publishing = ! $draft && ($data['status'] ?? null) === Translation::PUBLISHED;
        $rules = $writesTranslation ? [
            // A Singleton's form has no slug: what its Prefix breaks shows on the title.
            'title' => [$publishing ? 'required' : 'nullable', 'string', Field::encodingRule(), 'max:255', ...($contentType->hasSlug() ? [] : [$this->pathRule($contentType, $site, $locale)])],
            ...($contentType->hasSlug() ? ['slug' => [$publishing ? 'required' : 'nullable', 'string', 'max:255', $this->pathRule($contentType, $site, $locale)]] : []),
            'status' => ['required', 'in:draft,published'],
            'published_at' => ['nullable', 'date'],
            // As a Text, a Textarea and a Boolean Field check their own columns.
            ...Text::make('meta_title')->rulesAt('meta_title', false),
            ...Textarea::make('meta_description')->rulesAt('meta_description', false),
            ...Boolean::make('noindex')->rulesAt('noindex', false),
        ] : [];

        if ($contentType->isOrderable()) {
            // PostgreSQL's integer has no unsigned range, and the next new Entry takes one more.
            $rules['position'] = ['nullable', 'integer', 'min:0', 'max:2147483646'];
        }

        if ($contentType->isHierarchical()) {
            $rules['parent'] = ['nullable', 'string', $this->parentRule($contentType, $site, $entry)];
        }

        // A Shared value is read by every Translation: a save may not blank what another one publishes. One already blank holds nothing back, as a Field may turn required after publication.
        $held = array_filter($fields, fn (Field $field): bool => $field->isRequired() && ! $contentType->isTranslatable($field) && array_key_exists($field->handle, $values) && $before[$field->handle] !== null);
        $publishedElsewhere = ! $draft && $held !== [] && $entry instanceof Entry && Translation::query()->where('entry_id', $entry->id)->where('locale', '!=', $locale)->where('status', Translation::PUBLISHED)->exists();

        foreach ($fields as $handle => $field) {
            $rules = [...$rules, ...$field->rulesAt($handle, $publishing || ($publishedElsewhere && isset($held[$handle])))];
        }

        // `links.*.url` reads the label at `links.fields.url`; the keys stay as they are, naming the row for code.
        $names = [];

        foreach (array_keys($rules) as $attribute) {
            $label = match (true) {
                $attribute === 'seo_image' => (string) __('kami::seo.image'),
                isset($fields[Str::before($attribute, '.')]) => $contentType->fieldLabel(str_replace(['.*', '.'], ['', '.fields.'], $attribute)),
                in_array($attribute, ['title', 'slug', 'published_at', 'parent'], true) => (string) __("kami::admin.fields.{$attribute}"),
                default => null,
            };

            if ($label !== null) {
                // Lowered as Filament lowers the same label for the rules its form checks first, short of an acronym.
                $names[$attribute] = preg_match('/^\p{Lu}\p{Lu}/u', $label) === 1 ? $label : Str::lcfirst($label);
            }
        }

        // Conditions read their targets through a cast, which an invalid value could break: the targets pass first.
        $conditional = array_filter($contentType->flatFields(), fn (Field $field): bool => $field->conditionTarget() !== null);
        Validator::make($data, Arr::only($rules, array_map(fn (Field $field): ?string => $field->conditionTarget(), $conditional)), [], $names)->validate();

        $cleared = $clearedReferences = [];

        foreach ($conditional as $field) {
            // A target is Shared, so in scope of every call, and hides its Field in every Locale.
            $target = $fields[(string) $field->conditionTarget()];

            if (! $field->isVisibleGiven($target, $data[$target->handle])) {
                unset($values[$field->handle]);
                $rules = array_diff_key($rules, $field->rulesAt($field->handle, $publishing));

                // Stored values go only with a save that gives the target: a User who may not change it must not empty what visitors read (ADR 0019).
                if (! array_key_exists($target->handle, $values)) {
                    continue;
                }

                if ($field->isReference()) {
                    $clearedReferences[] = $field->handle;
                } else {
                    $cleared[$field->handle] = null;
                }
            }
        }

        Validator::make($data, $rules, [], $names)->validate();

        // A given relation keeps its links to trashed targets, which no form shows and `exists` refuses: restoring a target brings them back.
        foreach (array_intersect_key($fields, $values) as $handle => $field) {
            if (! $field instanceof RelationField) {
                continue;
            }

            /** @var list<string>|null $given */
            $given = $values[$handle];
            $kept = array_intersect($references[$contentType->isTranslatable($field) ? $locale : '*'][$handle] ?? [], $trashed);
            $values[$handle] = $kept === [] ? $given : [...$given ?? [], ...array_values(array_diff($kept, $given ?? []))];
        }

        return ['contentType' => $contentType, 'site' => $site, 'locale' => $locale, 'values' => $values, 'writesTranslation' => $writesTranslation, 'translation' => $translation, 'data' => $data, 'fields' => $fields, 'cleared' => $cleared, 'clearedReferences' => $clearedReferences];
    }

    /** What visitors may see changed: a publication, a scheduled one too, an unpublication, or a published date moved (ADR 0019, ADR 0022). A Draft save records nothing. */
    private function recordVisibility(Entry $entry, Translation $translation, bool $wasPublished, ?CarbonImmutable $publishedAt, ?Authenticatable $actor): void
    {
        $isPublished = $translation->status === Translation::PUBLISHED;
        $date = $translation->published_at;
        $context = ['locale' => $translation->locale];

        if ($isPublished && ! $wasPublished) {
            $event = 'kami.entries.published';
        } elseif (! $isPublished && $wasPublished) {
            $event = 'kami.entries.unpublished';
        } elseif ($isPublished && $publishedAt instanceof CarbonImmutable && $date instanceof CarbonImmutable && ! $publishedAt->equalTo($date)) {
            $event = 'kami.entries.publication-date-changed';
            $context += ['from' => $publishedAt->toIso8601String(), 'to' => $date->toIso8601String()];
        } else {
            return;
        }

        resolve(RecordAuditEvent::class)->handle($event, $entry, $context, $actor);
    }

    /**
     * Refuses a save over a change made since the caller loaded the Entry, naming the Last modifier through the panel, and the time in the Site's time zone.
     *
     * ponytail: read with no lock, to the second the column holds, so two saves within one second can both pass (spec #241); V1 adds a version column if a friction shows it.
     */
    private function refuseChangedSince(Entry $entry, DateTimeInterface $updatedAt, Site $site): void
    {
        $stored = Entry::query()->withUnpublished()->without('translations')->findOrFail($entry->id, ['id', 'updated_at', 'updated_by']);

        if ($stored->updated_at?->getTimestamp() === $updatedAt->getTimestamp()) {
            return;
        }

        $resolve = resolve(PluginRegistration::class)->userName;
        $name = $stored->updated_by === null || $resolve === null ? null : $resolve($stored->updated_by);

        throw ValidationException::withMessages(['updated_at' => __('kami::admin.changed_since.' . ($name === null ? 'unnamed' : 'named'), [
            'name' => $name,
            'time' => $stored->updated_at?->setTimezone($site->timezone)->isoFormat('LLL'),
        ])]);
    }

    /** The Path's characters, a Path Kami answers itself, an Archive's or a Feed's, then the default Locale's Paths, which `ResolveLocale` hides behind another Locale's code as first segment (ADR 0008). `kami:doctor` reports a stored Path one declared later hides. */
    private function pathRule(ContentType $contentType, Site $site, string $locale): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($contentType, $site, $locale): void {
            $path = is_string($value) ? Path::of($contentType, $value) : null;

            if ($path === null) {
                return;
            }

            if (! Path::isAnswerable($path)) {
                $fail(__('validation.regex'));
            } elseif (Path::isReserved($path) || Path::archiveAt($path) instanceof ContentType || Path::feedAt($path) !== null) {
                $fail(__('kami::admin.fields.slug_reserved'));
            } elseif (in_array($locale, [$site->defaultLocale(), '*'], true) && in_array($segment = explode('/', $path)[0], array_slice($site->locales, 1), true)) {
                $fail(__('kami::admin.fields.slug_locale', ['locale' => $segment]));
            }
        };
    }

    /**
     * An Entry of the type in the Site, that is neither the Entry nor under it.
     *
     * ponytail: read before the transaction, so two concurrent moves can still close a cycle, which `Ancestors` survives; V1 adds a lock on the type's tree if a friction shows it.
     */
    private function parentRule(ContentType $contentType, Site $site, ?Entry $entry): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($contentType, $site, $entry): void {
            if (! is_string($value)) {
                return;
            }

            if (! Entry::query()->withUnpublished()->ofType($contentType->handle())->where('site', $site->handle)->whereKey($value)->exists()) {
                $fail(__('validation.exists'));
            } elseif ($entry instanceof Entry && in_array($entry->id, Ancestors::ids($value), true)) {
                $fail(__('kami::admin.fields.parent_cycle'));
            }
        };
    }

    /**
     * The Translation's 200 rows, and its descendants' on a hierarchical type, whose Paths hold its slug: the `'*'` Translation answers in every Locale of the Site, one row each,
     * and a new Parent moves the Entry in every Locale. A moved row stays as a 301 to the Entry once public, and is rewritten in place otherwise (ADR 0008).
     */
    private function reservePaths(ContentType $contentType, Site $site, Entry $entry, string $locale, ?bool $wasPublic, bool $moves): void
    {
        $moved = $entry->wasChanged('parent_id');
        $written = $locale === '*' ? $site->locales : [$locale];
        $ids = $moves && $contentType->isHierarchical() && $contentType->isRoutable() ? SyncPaths::subtree($entry->id) : [$entry->id];
        $field = match (true) {
            $moved => 'parent',
            $contentType->hasSlug() => 'slug',
            default => 'title',
        };

        try {
            resolve(SyncPaths::class)->sync($site, $contentType, $ids, $moved ? $site->locales : $written, $wasPublic === null ? [] : array_fill_keys($written, $wasPublic));
        } catch (UniqueConstraintViolationException) {
            // Caught from the unique index, so two concurrent saves cannot both pass a prior lookup.
            throw ValidationException::withMessages([$field => match ($field) {
                'parent' => __('kami::admin.fields.parent_path_taken'),
                'slug' => __('validation.unique', ['attribute' => 'slug']),
                default => __('kami::admin.fields.path_taken'),
            }]);
        } catch (PathTooLong) {
            throw ValidationException::withMessages([$field => __('kami::admin.fields.path_too_long', ['max' => Path::MAX_LENGTH])]);
        }
    }
}
