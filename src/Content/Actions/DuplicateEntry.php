<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Events\EntryDuplicated;
use Psyao\Kami\Content\Internal\EntrySnapshot;
use Psyao\Kami\Content\Internal\References;
use Psyao\Kami\Content\Internal\ReorderEntries;
use Psyao\Kami\Content\Internal\Translation;
use Psyao\Kami\Content\Singleton;
use Psyao\Kami\Routing\Internal\Path;
use Psyao\Kami\Sites\Internal\Site;

/**
 * Copies an Entry with its Field values and References, last under the same Parent, every Translation a Draft with no date, so the copy cannot go live by accident. It copies no children and no Preview links, and writes directly, so it dispatches `EntryDuplicated` only. It does not authorise: the caller checks the actor's `create` Permission first (ADR 0011).
 */
final class DuplicateEntry
{
    /** The longest title and slug `SaveEntry` takes. */
    private const int MAX_LENGTH = 255;

    /**
     * @param  Entry  $source  The Entry to copy, outside the Trash.
     * @param  Authenticatable|null  $actor  Recorded as the Creator and Last modifier; null for imports and the CLI.
     * @return Entry The copy. Each title gains " (copy)" in its own Locale, each slug the first free `-copy`, `-copy-2`… in its Locales, a Redirect's or a 410's address counting as taken; a null title or slug stays null.
     *
     * @throws ValidationException On `entry`: a Singleton or a Global Set, which exists only once.
     */
    public function handle(Entry $source, ?Authenticatable $actor = null): Entry
    {
        if ($source->trashed()) {
            throw new InvalidArgumentException("The Entry [{$source->id}] is in the Trash: restore it first.");
        }

        $type = $source->contentType() ?? throw new LogicException("No Content Type [{$source->type}] is declared in kami.types.");

        if ($type instanceof Singleton) {
            throw ValidationException::withMessages(['entry' => __('kami::admin.duplicate.singleton')]);
        }

        // As stored: the caller's instance may hold Translations loaded before a later save. Any of them is given back as it is, so the snapshot holds every one unchanged.
        $source = Entry::query()->withUnpublished()->findOrFail($source->id);
        $read = $source->translations->firstOrFail();
        $snapshot = EntrySnapshot::capture($type, $source, ['locale' => $read->locale, 'data' => Arr::only($read->getAttributes(), Translation::ATTRIBUTES)], $read->locale);
        $site = Site::current();

        $id = DB::transaction(function () use ($type, $snapshot, $site, $actor): string {
            $parent = $snapshot['entry']['parent_id'] ?? null;
            $copy = (new Entry)->forceFill([
                'site' => $site->handle,
                'type' => $type->handle(),
                'parent_id' => $parent,
                'created_by' => $actor?->getAuthIdentifier(),
                'updated_by' => $actor?->getAuthIdentifier(),
                ...($type->isOrderable() ? ['position' => ReorderEntries::next($type, is_string($parent) ? $parent : null)] : []),
            ]);
            $copy->save();

            foreach ($snapshot['translations'] as $translation) {
                /** @var array{locale: string, title: string|null, slug: string|null} $translation */
                $locale = $translation['locale'];
                $locales = $locale === '*' ? $site->locales : [$locale];
                $slug = $translation['slug'] === null ? null : $this->freeSlug($type, $site, $locales, $translation['slug']);

                Translation::query()->create([
                    'entry_id' => $copy->id,
                    'locale' => $locale,
                    'title' => $translation['title'] === null ? null : $this->marked($translation['title'], $locale === '*' ? $site->defaultLocale() : $locale),
                    'slug' => $slug,
                    'status' => Translation::DRAFT,
                ]);

                $path = Path::of($type, $slug);

                foreach ($path === null ? [] : $locales as $pathLocale) {
                    Path::query()->create(['site' => $site->handle, 'locale' => $pathLocale, 'path' => $path, 'entry_id' => $copy->id, 'status' => 200]);
                }
            }

            foreach ($snapshot['rows'] as $row) {
                DB::table("kami_type_{$type->handle()}")->insert([...$row, 'entry_id' => $copy->id]);
            }

            // Links to trashed targets too, as `SaveEntry` keeps them: restoring a target brings them back on both.
            $relations = array_column($type->relationFields(), null, 'handle');

            foreach ($snapshot['references'] as $locale => $fields) {
                foreach ($fields as $handle => $targets) {
                    References::replace($copy->id, $relations[$handle], $locale === '*' ? null : $locale, $targets);
                }
            }

            return $copy->id;
        });

        $copy = Entry::query()->withUnpublished()->findOrFail($id);
        // No next-change file: a Draft with no date changes nothing visitors see.
        event(new EntryDuplicated($source, $copy, $actor));

        return $copy;
    }

    /** The title with " (copy)" in the Locale, the title shortened so the whole fits. */
    private function marked(string $title, string $locale): string
    {
        $room = self::MAX_LENGTH - mb_strlen(__('kami::admin.duplicate.title', ['title' => ''], $locale));

        return __('kami::admin.duplicate.title', ['title' => mb_substr($title, 0, $room)], $locale);
    }

    /**
     * The first of `-copy`, `-copy-2`… whose Path no row holds in any of the Locales, whatever its status.
     *
     * ponytail: read with no lock, so two concurrent copies can pick the same slug and the second fails on the unique index; V1 adds a retry if a friction shows it.
     *
     * @param  list<string>  $locales
     */
    private function freeSlug(ContentType $type, Site $site, array $locales, string $slug): string
    {
        for ($n = 1; ; $n++) {
            $suffix = $n === 1 ? '-copy' : "-copy-{$n}";
            $candidate = mb_substr($slug, 0, self::MAX_LENGTH - strlen($suffix)) . $suffix;
            $path = Path::of($type, $candidate);

            if ($path === null || ! Path::query()->where('site', $site->handle)->whereIn('locale', $locales)->where('path', $path)->exists()) {
                return $candidate;
            }
        }
    }
}
