<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal;

use Filament\Facades\Filament;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\GlobalSearch\GlobalSearchResults;
use Filament\GlobalSearch\Providers\DefaultGlobalSearchProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Internal\Translation;
use Psyao\Kami\Sites\Internal\Site;

/**
 * Filament's global search, Entries first: by Translation title and slug, one result per Locale. Filament's own skips Resources registered per configuration.
 * Set on Kami's panel by `KamiPlugin`, never bound in the container, so another panel keeps Filament's (ADR 0023).
 *
 * @internal
 */
final class KamiGlobalSearchProvider extends DefaultGlobalSearchProvider
{
    private const int LIMIT = 50;

    public function getResults(string $query): GlobalSearchResults
    {
        $builder = GlobalSearchResults::make();
        $types = $this->searchableTypes();
        $site = Site::current();
        // As the table's search: `whereLike()` is `ilike` on PostgreSQL and `like` elsewhere. A Locale the Site dropped has no screen to lead to.
        // ponytail: identical for ASCII only; SQLite's `like` tells `é` from `É`, MySQL's default collation also ignores accents. V1 if an editor meets it.
        $matching = fn (Builder | HasMany $translations): Builder | HasMany => $translations
            ->whereIn('locale', [...$site->locales, '*'])
            ->where(fn (Builder $where): Builder => $where->whereLike('title', "%{$query}%")->orWhereLike('slug', "%{$query}%"));

        // Ordered, so a search past the limit returns the same Entries on every engine.
        $entries = Entry::query()->withUnpublished()
            ->whereIn('type', $types)
            ->whereHas('translations', $matching)
            ->with(['translations' => $matching])
            ->latest('updated_at')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get();
        $order = array_flip($site->locales);
        $default = $site->defaultLocale();

        foreach ($types as $type) {
            EntryResource::withConfiguration($type, function () use ($builder, $entries, $type, $order, $default): void {
                $results = $entries->where('type', $type)->flatMap(fn (Entry $entry): array => $entry->translations
                    ->sortBy(fn (Translation $translation): int => $order[$translation->locale] ?? 0)
                    ->map(fn (Translation $translation): GlobalSearchResult => new GlobalSearchResult(
                        title: $translation->title ?? __('kami::admin.fields.untitled'),
                        url: EntryResource::editUrl($entry, in_array($translation->locale, ['*', $default], true) ? null : $translation->locale),
                        details: $translation->locale === '*' ? [] : [__('kami::admin.search.locale') => Str::upper($translation->locale)],
                    ))->all());

                if ($results->isNotEmpty()) {
                    $builder->category(EntryResource::getNavigationLabel(), $results);
                }
            });
        }

        /** @var array<string, array<GlobalSearchResult>> $filament */
        $filament = parent::getResults($query)?->getCategories()->all() ?? [];

        foreach ($filament as $label => $results) {
            $builder->category($label, $results);
        }

        return $builder;
    }

    /**
     * The handles of the types the User may list, in declaration order: none hides the field when no other Resource is searchable.
     *
     * @return list<string>
     */
    public function searchableTypes(): array
    {
        $types = [];

        foreach (Filament::getCurrentOrDefaultPanel()?->getResourceConfigurations() ?? [] as $configuration) {
            if ($configuration->getResource() === EntryResource::class && EntryResource::withConfiguration($configuration->getKey(), EntryResource::canAccess(...))) {
                $types[] = $configuration->getKey();
            }
        }

        return $types;
    }
}
