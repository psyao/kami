<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal\MediaResource;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use LogicException;
use Psyao\Kami\Admin\Internal\MediaResource;
use Psyao\Kami\Media\Actions\SaveMedia;
use Psyao\Kami\Media\Media;
use Psyao\Kami\Sites\Internal\Site;

/**
 * Edits a Media item's alt text and caption in one Locale, the URL's, as an Entry's Translation is edited; its credit and `decorative` are shared. Replace changes its file.
 *
 * @internal
 */
final class EditMedia extends EditRecord
{
    #[\Override]
    protected static string $resource = MediaResource::class;

    /** The Locale whose alt text and caption are edited. */
    #[Locked]
    public string $locale;

    public function mount(int | string $record, ?string $locale = null): void
    {
        $site = Site::current();

        // The default Locale has no segment of its own, as for Entries.
        abort_unless($locale === null || ($locale !== $site->defaultLocale() && in_array($locale, $site->locales, true)), 404);
        $this->locale = $locale ?? $site->defaultLocale();

        parent::mount($record);
    }

    public function getRecord(): Media
    {
        $media = parent::getRecord();

        return $media instanceof Media ? $media : throw new LogicException('EditMedia edits a Media item.');
    }

    public function getSubheading(): ?string
    {
        return count(Site::current()->locales) > 1 ? (string) __('kami::admin.locale', ['locale' => $this->locale]) : null;
    }

    /** The Locale's own values only: one fallen back from the default Locale, saved, would become this Locale's. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $media = $this->getRecord();
        $translation = $media->translations->firstWhere('locale', $this->locale);

        return ['alt' => $translation?->alt, 'caption' => $translation?->caption, 'credit' => $media->credit, 'decorative' => $media->isDecorative()];
    }

    protected function handleRecordUpdate(Model $record, array $data): Media
    {
        return resolve(SaveMedia::class)->handle(null, $data, media: $this->getRecord(), locale: $this->locale, actor: Filament::auth()->user());
    }

    protected function getHeaderActions(): array
    {
        return [
            ...(count(Site::current()->locales) > 1 ? [$this->localeSwitcher()] : []),
            $this->replaceAction(),
        ];
    }

    /** A file of the same family, through `SaveMedia`: everything referencing the Media item keeps it, and its Conversions get new URLs. */
    private function replaceAction(): Action
    {
        $limit = MediaResource::uploadLimit();

        return Action::make('replace')
            ->label(__('kami::admin.media.replace.label'))
            ->color('gray')
            ->modalDescription(__('kami::admin.media.replace.description'))
            ->schema([
                FileUpload::make('file')
                    ->label(__('kami::admin.media.replace.file'))
                    ->helperText(__('kami::admin.media.picker.limit', ['size' => Number::fileSize($limit, 1)]))
                    ->maxSize(intdiv($limit, 1024))
                    ->when($this->getRecord()->isImage(), fn (FileUpload $upload): FileUpload => $upload->image())
                    ->storeFiles(false)
                    ->required(),
            ])
            ->modalSubmitActionLabel(__('kami::admin.media.replace.submit'))
            ->action(function (array $data): void {
                $file = $data['file'] ?? null;

                try {
                    $this->record = resolve(SaveMedia::class)->handle($file instanceof UploadedFile ? $file : null, media: $this->getRecord(), actor: Filament::auth()->user());
                } catch (ValidationException $exception) {
                    MediaResource::refuseUpload($exception);
                }

                MediaResource::thumbnailsAfterResponse([$this->getRecord()]);
                Notification::make()->success()->title(__('kami::admin.media.replace.done'))->send();
            });
    }

    /** Every Locale; each other one is a link, as on an Entry's edit page. */
    private function localeSwitcher(): ActionGroup
    {
        $default = Site::current()->defaultLocale();

        return ActionGroup::make(array_map(function (string $locale) use ($default): Action {
            $action = Action::make("locale_{$locale}")->label(strtoupper($locale))->color('gray');

            return $locale === $this->locale
                ? $action->icon(Heroicon::Check)->disabled()->extraAttributes(['aria-current' => 'true'])
                : $action->url(MediaResource::getUrl('edit', ['record' => $this->getRecord(), 'locale' => $locale === $default ? null : $locale]));
        }, Site::current()->locales))
            ->label(strtoupper($this->locale))
            ->icon(Heroicon::Language)
            ->color('gray')
            ->button();
    }
}
