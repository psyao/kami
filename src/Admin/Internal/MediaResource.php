<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select as SelectInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource as FilamentResource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Psyao\Kami\Admin\Internal\MediaResource\EditMedia;
use Psyao\Kami\Admin\Internal\MediaResource\ListMedia;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Fields\Media as MediaField;
use Psyao\Kami\Content\Internal\EntryTitles;
use Psyao\Kami\Content\Internal\References;
use Psyao\Kami\Media\Actions\SaveMedia;
use Psyao\Kami\Media\ImagePreset;
use Psyao\Kami\Media\Internal\Conversion;
use Psyao\Kami\Media\Internal\ImagePresets;
use Psyao\Kami\Media\Media;
use Psyao\Kami\Sites\Internal\Site;
use Throwable;

use function Filament\Support\generate_icon_html;
use function Illuminate\Support\defer;

/**
 * The Site's Media library: images and documents, uploaded several at a time through `SaveMedia` (ADR 0017).
 *
 * @internal
 *
 * @extends FilamentResource<Media>
 */
final class MediaResource extends FilamentResource
{
    /** Enough to find each one, within shared hosting's memory. */
    private const int USED_BY_SHOWN = 50;

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

    /** Its alt text in any Locale too: the words an editor remembers an image by. */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'translations.alt'];
    }

    /** Ordered, so a search past Filament's limit returns the same items on every engine. */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->orderBy('name')->orderBy('id');
    }

    /** The edit page under `kami.media.update`; otherwise the library searched for its name, as no page shows one item read-only. */
    public static function getGlobalSearchResultUrl(Model $record): string
    {
        return self::canEdit($record) ? self::getUrl('edit', ['record' => $record]) : self::getUrl(parameters: ['search' => $record->getAttribute('name')]);
    }

    /** As translated: Filament would title-case it. */
    public static function getNavigationLabel(): string
    {
        return self::getPluralModelLabel();
    }

    /**
     * Alt text and caption in the Locale edited, with its `lang` (WCAG 3.1.2); credit and `decorative` shared. Alt text is required
     * only here, on an image's default Locale, unless it is decorative: an upload or an import may leave it missing (ADR 0017).
     */
    public static function form(Schema $schema): Schema
    {
        $site = Site::current();
        $livewire = $schema->getLivewire();
        $locale = $livewire instanceof EditMedia ? $livewire->locale : $site->defaultLocale();
        $lang = count($site->locales) > 1 ? ['lang' => $locale] : [];
        $isImage = fn (?Media $record): bool => $record?->isImage() ?? false;

        return $schema->columns(1)->components([
            // Decorative: the page's heading names the file (WCAG 1.1.1).
            ImageEntry::make('preview')
                ->label(__('kami::admin.media.fields.preview'))
                ->state(fn (Media $record): ?string => self::preview($record))
                ->visible(fn (Media $record): bool => self::preview($record) !== null)
                ->imageSize(160),
            TextInput::make('alt')
                ->label(__('kami::admin.media.fields.alt'))
                ->helperText(__('kami::admin.media.fields.alt_help'))
                ->extraInputAttributes($lang)
                ->maxLength(1000)
                ->visible($isImage)
                ->required(fn (Get $get): bool => $locale === $site->defaultLocale() && ! $get('decorative')),
            Toggle::make('decorative')
                ->label(__('kami::admin.media.fields.decorative'))
                ->helperText(__('kami::admin.media.fields.decorative_help'))
                ->visible($isImage)
                ->live(),
            Textarea::make('caption')->label(__('kami::admin.media.fields.caption'))->extraInputAttributes($lang)->rows(2),
            TextInput::make('credit')->label(__('kami::admin.media.fields.credit'))->maxLength(255),
            TextEntry::make('used_by')
                ->label(__('kami::admin.media.used_by.label'))
                ->state(fn (Media $record): HtmlString => self::usedBy($record))
                ->prose(),
        ]);
    }

    /**
     * The first Entries using it, then how many more, each linked to its edit page. One in the Trash, of a type the User cannot open,
     * or of a type no longer declared has no page to link to, and is named only: its title is in `DeleteMedia`'s refusal anyway.
     */
    private static function usedBy(Media $media): HtmlString
    {
        $sources = References::mediaSources($media->id);
        $count = $sources->count();
        $linkable = EntryResource::accessibleTypes();

        $items = $sources->limit(self::USED_BY_SHOWN)->get()->map(function (Entry $entry) use ($linkable): string {
            $title = e(EntryTitles::marked($entry));

            return $entry->trashed() || ! in_array($entry->type, $linkable, true)
                ? "<li>{$title}</li>"
                : '<li><a href="' . e(EntryResource::withConfiguration($entry->type, fn (): string => EntryResource::editUrl($entry, null))) . "\">{$title}</a></li>";
        });

        $more = $count > self::USED_BY_SHOWN ? '<li>' . e(__('kami::admin.media.used_by.more', ['count' => $count - self::USED_BY_SHOWN])) . '</li>' : '';

        return new HtmlString($items->isEmpty() ? e(__('kami::admin.media.used_by.none')) : "<ul>{$items->implode('')}{$more}</ul>");
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Decorative: the name beside it says what the row is, and links to it; a link around the thumbnail alone would have no name (WCAG 1.1.1, 2.4.4).
                ImageColumn::make('thumbnail')
                    ->disabledClick()
                    ->label(__('kami::admin.media.columns.thumbnail'))
                    ->state(fn (Media $record): ?string => self::preview($record))
                    ->square()
                    ->imageSize(48),
                TextColumn::make('name')->label(__('kami::admin.media.columns.name'))->searchable(),
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
                Filter::make('alt_missing')->label(__('kami::admin.media.filters.alt_missing'))
                    ->query(function (Builder $query): void {
                        $query->scopes('missingAlt');
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginationPageOptions([10, 25, 50]);
    }

    /** `missing`: an image, not decorative, with a blank alt text in the default Locale, as `Media::missingAlt()` finds it. */
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
     * A Media Field's input: searched on the server by name and alt text in any Locale, each option its thumbnail and name.
     * A multiple one reorders as `Entries` does (ADR 0007). Under `kami.media.create`, a file uploaded from it goes through `SaveMedia` and is picked.
     */
    public static function picker(MediaField $field): SelectInput
    {
        $label = __('kami::admin.media.picker.upload', ['field' => EntryResource::type()->fieldLabel($field->handle)]);

        return SelectInput::make($field->handle)
            ->multiple($field->isMultiple())
            ->reorderable($field->isMultiple())
            ->searchable()
            // Every name is escaped in `options()`.
            ->allowHtml()
            ->getSearchResultsUsing(fn (string $search): array => self::options($field->linkable()
                ->where(fn (Builder $query) => $query->whereLike('name', "%{$search}%")->orWhereHas('translations', fn (Builder $translation) => $translation->whereLike('alt', "%{$search}%")))
                ->orderBy('name')->limit(50)->get()))
            ->getOptionLabelsUsing(fn (array $values): array => self::options(Media::query()->whereKey($values)->get()))
            ->getOptionLabelUsing(fn (string $value): ?string => self::options(Media::query()->whereKey($value)->get())[$value] ?? null)
            ->createOptionForm([
                FileUpload::make('file')
                    ->label(__('kami::admin.media.picker.file'))
                    ->helperText(__('kami::admin.media.picker.limit', ['size' => Number::fileSize(self::uploadLimit(), 1)]))
                    ->maxSize(intdiv(self::uploadLimit(), 1024))
                    ->when($field->acceptsImagesOnly(), fn (FileUpload $upload): FileUpload => $upload->image())
                    ->storeFiles(false)
                    ->required(),
            ])
            ->createOptionAction(fn (Action $action): Action => $action->authorize(fn (): bool => self::canCreate())
                // Named, as a form may hold several pickers (WCAG 2.4.6).
                ->label($label)
                ->modalHeading($label))
            ->createOptionUsing(function (array $data): string {
                $file = $data['file'] ?? null;

                try {
                    $media = resolve(SaveMedia::class)->handle($file instanceof UploadedFile ? $file : null, actor: Filament::auth()->user());
                } catch (ValidationException $exception) {
                    self::refuseUpload($exception);
                }

                self::thumbnailsAfterResponse([$media]);

                return $media->id;
            });
    }

    /**
     * Shows `SaveMedia`'s refusal on the `file` input of the action open, the picker's or Replace file's.
     *
     * ponytail: Filament's internal state path of an action opened first, as `EntryResource::withQuickCreate()`; V1 lifts all three.
     */
    public static function refuseUpload(ValidationException $exception): never
    {
        throw ValidationException::withMessages(['mountedActions.0.data.file' => Arr::flatten($exception->errors())]);
    }

    /** In bytes, per file: the lowest of PHP's `upload_max_filesize` and `post_max_size` and Livewire's rule for temporary uploads, which every upload passes first. */
    public static function uploadLimit(): int
    {
        $limit = UploadedFile::getMaxFilesize();

        foreach (Arr::wrap(FileUploadConfiguration::rules()) as $rule) {
            if (is_string($rule) && preg_match('/^max:(\d+)$/', $rule, $match) === 1) {
                $limit = min($limit, (int) $match[1] * 1024);
            }
        }

        return (int) $limit;
    }

    /**
     * Writes each `admin-thumb` after the response, so the editor does not wait for them; one that fails is generated on its first request instead.
     *
     * @param  array<Media>  $saved
     */
    public static function thumbnailsAfterResponse(array $saved): void
    {
        defer(function () use ($saved): void {
            foreach ($saved as $media) {
                try {
                    self::thumbnail($media)?->write();
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        });
    }

    /**
     * Each Media item's preview, decorative beside its name (WCAG 1.1.1), or a document icon for a document, which has none.
     *
     * @param  iterable<Media>  $media
     * @return array<string, string>
     */
    private static function options(iterable $media): array
    {
        $options = [];

        foreach ($media as $item) {
            $thumbnail = self::preview($item);
            $preview = $thumbnail === null
                ? (string) generate_icon_html(Heroicon::OutlinedDocument)?->toHtml()
                : '<img src="' . e($thumbnail) . '" alt="" width="32" height="32" style="object-fit: cover; border-radius: 0.25rem">';
            $options[$item->id] = '<span style="display: flex; align-items: center; gap: 0.5rem">' . $preview . e($item->name) . '</span>';
        }

        return $options;
    }

    /** The URL of what the admin shows of a Media item: a raster image's `admin-thumb`, an SVG's own file, none for a document. */
    public static function preview(Media $media): ?string
    {
        return $media->isImage() && ! Conversion::isConvertible($media) ? $media->url() : self::thumbnail($media)?->url();
    }

    /** The `admin-thumb` Conversion of a raster image. */
    public static function thumbnail(Media $media): ?Conversion
    {
        $preset = resolve(ImagePresets::class)->find(ImagePresets::ADMIN_THUMB);

        return $preset instanceof ImagePreset ? Conversion::find($media, $preset->handle, $preset->widths[0], Conversion::fallbackFormat($media)) : null;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMedia::route('/'),
            // The default Locale's at `/edit`, another's at `/edit/fr`, as for Entries.
            'edit' => EditMedia::route('/{record}/edit/{locale?}'),
        ];
    }
}
