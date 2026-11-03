<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Events\TranslationDeleted;
use Psyao\Kami\Content\Internal\PreviewLink;
use Psyao\Kami\Content\Internal\Translation;
use Psyao\Kami\Content\Internal\Visibility;
use Psyao\Kami\Routing\Internal\Path;
use Psyao\Kami\Sites\Internal\Site;

/**
 * Deletes a Translation that was never published, with its Field values, References and Preview links in that Locale, and frees its Paths; Shared values stay with the Entry. It does not authorise: the caller checks the actor's `delete` Permission first (ADR 0011).
 */
final class DeleteTranslation
{
    /**
     * @param  Authenticatable|null  $actor  Recorded as the Last modifier; null for imports and the CLI.
     *
     * @throws ValidationException On `locale`: one published at least once, which unpublishing or the Trash handle instead, or the Entry's only one.
     */
    public function handle(Entry $entry, string $locale, ?Authenticatable $actor = null): void
    {
        if ($entry->trashed()) {
            throw new InvalidArgumentException("The Entry [{$entry->id}] is in the Trash: restore it first.");
        }

        $translations = Translation::query()->where('entry_id', $entry->id)->get();
        $translation = $translations->firstWhere('locale', $locale) ?? throw new InvalidArgumentException("The Entry [{$entry->id}] has no Translation in [{$locale}].");

        // ADR 0019: unpublishing keeps the date, so a date means published at least once.
        if ($translation->published_at !== null) {
            throw ValidationException::withMessages(['locale' => __('kami::admin.delete_translation.published')]);
        }

        if ($translations->count() === 1) {
            throw ValidationException::withMessages(['locale' => __('kami::admin.delete_translation.last')]);
        }

        DB::transaction(function () use ($entry, $locale, $translation, $actor): void {
            $translation->delete();
            DB::table("kami_type_{$entry->type}")->where(['entry_id' => $entry->id, 'locale' => $locale])->delete();
            DB::table('kami_references')->where(['source_entry_id' => $entry->id, 'locale' => $locale])->delete();
            PreviewLink::query()->where(['entry_id' => $entry->id, 'locale' => $locale])->delete();
            // Never public, so no address to keep as a Redirect or a 410.
            Path::query()->where(['entry_id' => $entry->id, 'locale' => $locale, 'status' => 200])->delete();
            $entry->forceFill(['updated_by' => $actor?->getAuthIdentifier(), 'updated_at' => $entry->freshTimestamp()])->save();
        });

        DB::afterCommit(fn () => Visibility::writeNextChange(Site::current()));
        event(new TranslationDeleted($entry->id, $entry->type, $entry->site, $locale, $actor));
    }
}
