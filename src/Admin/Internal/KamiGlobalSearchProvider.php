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
        // lower() on both sides, as Filament's forced case-insensitive search: `like` alone follows MySQL's collation and is case-sensitive on PostgreSQL.
        // ponytail: identical for ASCII only; SQLite's lower() leaves `É` as is, MySQL's default collation also ignores accents. V1 if an editor meets it.
        $like = '%' . mb_strtolower($query) . '%';
        $matching = fn (Builder | HasMany $translations): Builder | HasMany => $translations->where(fn (Builder $where): Builder => $where
            ->whereRaw('lower(title) like ?', [$like])
            ->orWhereRaw('lower(slug) like ?', [$like]));

        $entries = Entry::query()->withUnpublished()
            ->whereIn('type', $types)
            ->whereHas('translations', $matching)
            ->with(['translations' => $matching])
            ->limit(self::LIMIT)
            ->get();
        $order = array_flip(Site::current()->locales);

        foreach ($types as $type) {
            EntryResource::withConfiguration($type, function () use ($builder, $entries, $type, $order): void {
                $results = $entries->where('type', $type)->flatMap(fn (Entry $entry): array => $entry->translations
                    ->sortBy(fn (Translation $translation): int => $order[$translation->locale] ?? count($order))
                    ->map(fn (Translation $translation): GlobalSearchResult => new GlobalSearchResult(
                        title: $translation->title ?? __('kami::admin.fields.untitled'),
                        url: EntryResource::editUrl($entry, in_array($translation->locale, ['*', Site::current()->defaultLocale()], true) ? null : $translation->locale),
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
