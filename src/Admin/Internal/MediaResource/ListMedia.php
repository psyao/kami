<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal\MediaResource;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Psyao\Kami\Admin\Internal\MediaResource;
use Psyao\Kami\Media\Actions\SaveMedia;
use Throwable;

use function Illuminate\Support\defer;

/** @internal */
final class ListMedia extends ListRecords
{
    /** Files saved in one request: each is read and decoded in full, within shared hosting's time limit. */
    private const int MAX_FILES = 20;

    #[\Override]
    protected static string $resource = MediaResource::class;

    protected function getHeaderActions(): array
    {
        $limit = $this->uploadLimit();

        return [
            Action::make('upload')
                ->label(__('kami::admin.media.upload.label'))
                ->authorize(fn (): bool => MediaResource::canCreate())
                ->schema([
                    FileUpload::make('files')
                        ->label(__('kami::admin.media.upload.files'))
                        ->helperText(__('kami::admin.media.upload.limits', ['count' => self::MAX_FILES, 'size' => Number::fileSize($limit, 1)]))
                        ->multiple()
                        ->maxFiles(self::MAX_FILES)
                        // Checked in the browser before the upload starts, naming the limit; over it, PHP would drop the file without a word.
                        ->maxSize(intdiv($limit, 1024))
                        // Handed to `SaveMedia` as they are: it stores the original on its own disk.
                        ->storeFiles(false)
                        ->required(),
                ])
                ->modalSubmitActionLabel(__('kami::admin.media.upload.submit'))
                ->action(fn (array $data) => $this->upload(array_filter(Arr::wrap($data['files'] ?? null), fn (mixed $file): bool => $file instanceof UploadedFile))),
        ];
    }

    /** In bytes, per file: the lowest of PHP's `upload_max_filesize` and `post_max_size` and Livewire's rule for temporary uploads, which every upload passes first. */
    private function uploadLimit(): int
    {
        $limit = UploadedFile::getMaxFilesize();

        foreach (Arr::wrap(FileUploadConfiguration::rules()) as $rule) {
            if (is_string($rule) && preg_match('/^max:(\d+)$/', $rule, $match) === 1) {
                $limit = min($limit, (int) $match[1] * 1024);
            }
        }

        return (int) $limit;
    }

    private function refusal(UploadedFile $file, string $reason): string
    {
        return '<strong>' . e($file->getClientOriginalName()) . '</strong>: ' . e($reason);
    }

    /** @param  array<UploadedFile>  $files */
    private function upload(array $files): void
    {
        $saved = [];
        $refused = [];

        foreach ($files as $file) {
            try {
                $saved[] = resolve(SaveMedia::class)->handle($file, actor: Filament::auth()->user());
            } catch (ValidationException $exception) {
                $refused[] = $this->refusal($file, $exception->validator->errors()->first());
            } catch (Throwable $exception) {
                // Logged, never shown: one file that fails for another reason does not cost the editor the others.
                report($exception);
                $refused[] = $this->refusal($file, __('kami::admin.media.upload.failed'));
            }
        }

        // After the response, so the editor does not wait for them; one that fails is generated on its first request instead.
        defer(function () use ($saved): void {
            foreach ($saved as $media) {
                try {
                    MediaResource::thumbnail($media)?->write();
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        });

        if ($saved !== []) {
            Notification::make()->success()->title(trans_choice('kami::admin.media.upload.saved', count($saved)))->send();
        }

        // Kept until dismissed, so the editor reads every reason at their own pace (WCAG 2.2.1).
        if ($refused !== []) {
            Notification::make()->danger()->persistent()
                ->title(trans_choice('kami::admin.media.upload.refused', count($refused)))
                ->body('<ul><li>' . implode('</li><li>', $refused) . '</li></ul>')
                ->send();
        }
    }
}
