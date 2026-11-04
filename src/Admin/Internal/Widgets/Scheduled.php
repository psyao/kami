<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal\Widgets;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Psyao\Kami\Admin\Internal\EntryResource;
use Psyao\Kami\Content\Internal\Translation;
use Psyao\Kami\Sites\Internal\Site;

/**
 * The published Translations whose date is to come, soonest first, of the types the User may list and out of the Trash.
 *
 * @internal
 */
final class Scheduled extends TableWidget
{
    private const int SHOWN = 10;

    #[\Override]
    protected static ?int $sort = 11;

    public static function canView(): bool
    {
        return EntryResource::accessibleTypes() !== [];
    }

    public function table(Table $table): Table
    {
        $types = fn (Builder $entries): Builder => $entries->whereIn('type', EntryResource::accessibleTypes());

        return $table
            ->heading(__('kami::admin.widgets.scheduled.heading'))
            ->query(fn () => Translation::query()
                ->where('status', Translation::PUBLISHED)
                ->where('published_at', '>', now())
                // A Locale the Site dropped has no screen to lead to.
                ->whereIn('locale', [...Site::current()->locales, '*'])
                ->whereHas('entry', $types)
                ->with('entry')
                ->oldest('published_at')
                ->orderBy('id')
                ->limit(self::SHOWN))
            ->paginated(false)
            ->emptyStateHeading(__('kami::admin.widgets.scheduled.empty'))
            ->columns([
                TextColumn::make('title')->label(__('kami::admin.fields.title'))->default(__('kami::admin.fields.untitled')),
                TextColumn::make('type')->label(__('kami::admin.widgets.type'))->state(fn (Translation $record): string => EntryResource::withConfiguration($record->entry->type, EntryResource::getModelLabel(...))),
                TextColumn::make('locale')->label(__('kami::admin.search.locale'))->state(fn (Translation $record): ?string => $record->locale === '*' ? null : Str::upper($record->locale)),
                TextColumn::make('published_at')->label(__('kami::admin.fields.published_at'))->dateTime(timezone: Site::current()->timezone),
            ])
            ->recordUrl(fn (Translation $record): string => EntryResource::withConfiguration($record->entry->type, fn (): string => EntryResource::editUrl($record->entry, $record->locale)));
    }
}
