<?php

declare(strict_types=1);

namespace Psyao\Kami\Media\Actions;

use finfo;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Image\Driver;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use Psyao\Kami\Media\Events\MediaSaved;
use Psyao\Kami\Media\Internal\MediaTranslation;
use Psyao\Kami\Media\Media;
use Psyao\Kami\Sites\Internal\Site;
use RuntimeException;
use Symfony\Component\Mime\MimeTypes;
use Throwable;

/**
 * Stores a file in the Site's Media library, or writes a Media item's alt text, caption, credit and `decorative`: the one way Media is written, from the admin, seeders or imports alike. It does not authorise: the caller checks the actor's Permissions first (ADR 0011).
 *
 * The file's MIME type is read from its content against `kami.media.mimes`, and the stored extension follows it; an image must decode and stay within `kami.media.max_pixels`. Refusals throw Laravel's `ValidationException` on `file`, naming the reason.
 */
final class SaveMedia
{
    /** Closed whatever `kami.media.mimes` says: nothing half-supported gets in (V1). */
    private const array CLOSED_FAMILIES = ['video', 'audio'];

    /**
     * @param  UploadedFile|string|null  $file  An upload, or a local path; null to write the metadata of an existing Media item only.
     * @param  array<string, mixed>  $values  `alt` and `caption`, in the Locale written; `credit` and `decorative`, shared by every Locale. A value left out keeps its stored one; `''` stores `null`.
     * @param  Media|null  $media  The Media item to update; a new one when null.
     * @param  string|null  $locale  The Locale `alt` and `caption` are written in, the default one when null.
     * @param  Authenticatable|null  $actor  Who saves; null for imports and the CLI.
     *
     * @throws ValidationException
     */
    public function handle(UploadedFile | string | null $file, array $values = [], ?Media $media = null, ?string $locale = null, ?Authenticatable $actor = null): Media
    {
        $site = Site::current();
        $locale ??= $site->defaultLocale();

        if (! in_array($locale, $site->locales, true)) {
            throw new InvalidArgumentException("No Locale [{$locale}] is declared in Site [{$site->handle}].");
        }

        // ponytail: no replacement yet; #302 replaces the file of an existing Media item within its family.
        if ($file !== null && $media instanceof Media) {
            throw new LogicException('Replacing a Media item\'s file is not supported yet.');
        }

        $values = array_map(fn (mixed $value): mixed => $value === '' ? null : $value, $values);
        Validator::make($values, [
            'alt' => ['nullable', 'string'],
            'caption' => ['nullable', 'string'],
            'credit' => ['nullable', 'string', 'max:255'],
            'decorative' => ['boolean'],
            ...array_fill_keys(array_keys(Arr::except($values, ['alt', 'caption', 'credit', 'decorative'])), 'prohibited'),
        ])->validate();

        $media ??= $this->newMedia($site, $file ?? throw new InvalidArgumentException('A new Media item needs a file.'));
        $created = ! $media->exists;

        $id = DB::transaction(function () use ($locale, $values, $media, $file, $created): string {
            foreach (Arr::only($values, ['credit', 'decorative']) as $attribute => $value) {
                $media->setAttribute($attribute, $value);
            }

            $media->save();

            $translated = Arr::only($values, MediaTranslation::ATTRIBUTES);
            if ($translated !== []) {
                $translation = MediaTranslation::query()->firstOrNew(['media_id' => $media->id, 'locale' => $locale]);
                foreach ($translated as $attribute => $value) {
                    $translation->setAttribute($attribute, $value);
                }

                $translation->save();
            }

            // Last, so a row that fails to save leaves no file.
            if ($created && $file !== null) {
                // ponytail: an outer transaction rolled back afterwards leaves the file behind; M9 cleans up after a failed import.
                Storage::disk(Config::string('kami.media.originals_disk'))->putFileAs('media', is_string($file) ? new File($file) : $file, basename($media->file))
                    ?: throw new RuntimeException("The original of Media [{$media->id}] could not be written.");
            }

            return $media->id;
        });

        $saved = Media::query()->findOrFail($id);
        // After commit, so a listener never sees a write rolled back; a caller's own transaction defers it to its commit (ADR 0029).
        event(new MediaSaved($saved, $locale, $created, $actor));

        return $saved;
    }

    /**
     * Unsaved, with what Kami reads from the file itself, never from its name but for `name`.
     *
     * @throws ValidationException
     */
    private function newMedia(Site $site, UploadedFile | string $file): Media
    {
        $path = is_string($file) ? $file : $file->getRealPath();

        if ($path === false || ! is_file($path)) {
            throw new InvalidArgumentException('No readable file at [' . (is_string($file) ? $file : $file->getClientOriginalName()) . '].');
        }

        $mime = (string) new finfo(FILEINFO_MIME_TYPE)->file($path);
        /** @var array<string, list<string>> $allowed */
        $allowed = Arr::except(Config::array('kami.media.mimes'), self::CLOSED_FAMILIES);
        $family = array_find_key($allowed, fn (array $mimes): bool => in_array($mime, $mimes, true))
            ?? throw ValidationException::withMessages(['file' => __('kami::admin.media.refused.type', ['type' => $mime])]);

        [$width, $height] = match (true) {
            $family !== 'image' => [null, null],
            $mime === 'image/svg+xml' => $this->svgDimensions($path),
            default => $this->rasterDimensions($path),
        };

        $media = new Media;
        $id = $media->newUniqueId();

        return $media->forceFill([
            'id' => $id,
            'site' => $site->handle,
            'file' => "media/{$id}." . (MimeTypes::getDefault()->getExtensions($mime)[0] ?? 'bin'),
            'name' => Str::slug(pathinfo(is_string($file) ? $file : $file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'media',
            'mime' => $mime,
            'family' => $family,
            'size' => (int) filesize($path),
            'width' => $width,
            'height' => $height,
            'checksum' => (string) hash_file('sha256', $path),
        ]);
    }

    /**
     * The header's size first, so an image over the limit is refused before it is decoded; then a full decode by the image driver.
     *
     * @return array{int, int}
     *
     * @throws ValidationException
     */
    private function rasterDimensions(string $path): array
    {
        $size = @getimagesize($path);

        if ($size === false) {
            throw ValidationException::withMessages(['file' => __('kami::admin.media.refused.undecodable')]);
        }

        $limit = Config::integer('kami.media.max_pixels');
        if ($limit < $size[0] * $size[1]) {
            throw ValidationException::withMessages(['file' => __('kami::admin.media.refused.pixels', ['megapixels' => round($limit / 1_000_000, 3)])]);
        }

        $driver = Image::driver();
        if (! $driver instanceof Driver) {
            throw new LogicException('The image driver does not implement ' . Driver::class . '.');
        }

        try {
            return $driver->dimensions((string) file_get_contents($path));
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => __('kami::admin.media.refused.undecodable')]);
        }
    }

    /**
     * From its `width` and `height` in pixels, or its `viewBox`; never decoded by GD or Imagick (ADR 0017).
     *
     * @return array{int|null, int|null}
     *
     * @throws ValidationException
     */
    private function svgDimensions(string $path): array
    {
        $svg = @simplexml_load_file($path, options: LIBXML_NONET);

        if ($svg === false || $svg->getName() !== 'svg') {
            throw ValidationException::withMessages(['file' => __('kami::admin.media.refused.undecodable')]);
        }

        $pixels = fn (string $length): ?int => preg_match('/^\s*(\d+(?:\.\d+)?)\s*(px)?\s*$/', $length, $match) === 1 ? (int) round((float) $match[1]) : null;
        $width = $pixels((string) $svg['width']);
        $height = $pixels((string) $svg['height']);

        if (($width === null || $height === null) && preg_match('/^\s*\S+[\s,]+\S+[\s,]+(\d+(?:\.\d+)?)[\s,]+(\d+(?:\.\d+)?)\s*$/', (string) $svg['viewBox'], $box) === 1) {
            return [(int) round((float) $box[1]), (int) round((float) $box[2])];
        }

        return [$width, $height];
    }
}
