<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal\Widgets;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Psyao\Kami\Admin\Internal\EntryResource;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Internal\EntryTitles;
use Psyao\Kami\Sites\Internal\Site;

/**
 * The last Entries edited by anyone, of the types the User may list, Drafts included and the Trash left out.
 *
 * @internal
 */
final class RecentlyEdited extends TableWidget
{
    private const int SHOWN = 10;

    #[\Override]
    protected static ?int $sort = 10;

    public static function canView(): bool
    {
        return EntryResource::accessibleTypes() !== [];
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('kami::admin.widgets.recently_edited.heading'))
            ->query(fn () => Entry::query()->withUnpublished()
                ->whereIn('type', EntryResource::accessibleTypes())
                ->with('translations')
                ->latest('updated_at')
                ->orderByDesc('id')
                ->limit(self::SHOWN))
            ->paginated(false)
            ->emptyStateHeading(__('kami::admin.widgets.recently_edited.empty'))
            ->columns([
                TextColumn::make('title')->label(__('kami::admin.fields.title'))->state(fn (Entry $record): string => EntryTitles::of($record)),
                TextColumn::make('type')->label(__('kami::admin.widgets.type'))->state(fn (Entry $record): string => EntryResource::withConfiguration($record->type, EntryResource::getModelLabel(...))),
                TextColumn::make('updated_at')->label(__('kami::admin.widgets.recently_edited.updated_at'))->since()->dateTimeTooltip(timezone: Site::current()->timezone),
            ])
            ->recordUrl(fn (Entry $record): string => EntryResource::withConfiguration($record->type, fn (): string => EntryResource::editUrl($record, null)));
    }
}
