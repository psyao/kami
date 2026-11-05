<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal;

use Filament\Resources\Resource as FilamentResource;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Number;
use Psyao\Kami\Admin\Internal\MediaResource\ListMedia;
use Psyao\Kami\Media\ImagePreset;
use Psyao\Kami\Media\Internal\Conversion;
use Psyao\Kami\Media\Internal\ImagePresets;
use Psyao\Kami\Media\Media;
use Psyao\Kami\Sites\Internal\Site;

/**
 * The Site's Media library: images and documents, uploaded several at a time through `SaveMedia` (ADR 0017).
 *
 * @internal
 *
 * @extends FilamentResource<Media>
 */
final class MediaResource extends FilamentResource
{
    #[\Override]
    protected static ?string $model = Media::class;

    #[\Override]
    protected static ?string $slug = 'media';

    #[\Override]
    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('kami::admin.media.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('kami::admin.media.plural_label');
    }

    /** As translated: Filament would title-case it. */
    public static function getNavigationLabel(): string
    {
        return self::getPluralModelLabel();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Decorative: the name beside it says what the row is (WCAG 1.1.1).
                ImageColumn::make('thumbnail')
                    ->label(__('kami::admin.media.columns.thumbnail'))
                    ->state(fn (Media $record): ?string => self::thumbnail($record)?->url())
                    ->square()
                    ->imageSize(48),
                TextColumn::make('name')->label(__('kami::admin.media.columns.name')),
                // From `md` up: on a phone the rows would scroll sideways, a region a keyboard cannot reach with no link in it (WCAG 2.1.1).
                TextColumn::make('family')->label(__('kami::admin.media.columns.family'))->visibleFrom('md')
                    ->formatStateUsing(fn (string $state): string => __("kami::admin.media.families.{$state}")),
                TextColumn::make('size')->label(__('kami::admin.media.columns.size'))->visibleFrom('md')
                    ->formatStateUsing(fn (int $state): string => Number::fileSize($state, 1)),
                TextColumn::make('alt')->label(__('kami::admin.media.columns.alt'))
                    ->state(fn (Media $record): string => __('kami::admin.media.alt.' . self::altState($record)))
                    ->badge()
                    ->color(fn (Media $record): string => self::altState($record) === 'missing' ? 'warning' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('family')->label(__('kami::admin.media.columns.family'))
                    ->options(['image' => __('kami::admin.media.families.image'), 'document' => __('kami::admin.media.families.document')]),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginationPageOptions([10, 25, 50]);
    }

    /** `missing`: an image, not decorative, with a blank alt text in the default Locale. */
    private static function altState(Media $media): string
    {
        $alt = $media->translations->firstWhere('locale', Site::current()->defaultLocale())?->alt;

        return match (true) {
            ! $media->isImage() => 'not_needed',
            $media->isDecorative() => 'decorative',
            is_string($alt) && trim($alt) !== '' => 'described',
            default => 'missing',
        };
    }

    /**
     * The `admin-thumb` Conversion of a raster image.
     *
     * ponytail: none for an SVG, whose file has no URL yet; #300 serves it as it is.
     */
    public static function thumbnail(Media $media): ?Conversion
    {
        $preset = resolve(ImagePresets::class)->find(ImagePresets::ADMIN_THUMB);

        return $preset instanceof ImagePreset ? Conversion::find($media, $preset->handle, $preset->widths[0], Conversion::fallbackFormat($media)) : null;
    }

    public static function getPages(): array
    {
        return ['index' => ListMedia::route('/')];
    }
}
