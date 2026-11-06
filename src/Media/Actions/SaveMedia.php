<?php

declare(strict_types=1);

namespace Psyao\Kami\Media\Actions;

use DOMDocument;
use DOMElement;
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
use Psyao\Kami\Media\Internal\Conversion;
use Psyao\Kami\Media\Internal\MediaTranslation;
use Psyao\Kami\Media\Media;
use Psyao\Kami\Sites\Internal\Site;
use RuntimeException;
use Symfony\Component\Mime\MimeTypes;
use Throwable;

/**
 * Stores a file in the Site's Media library, replaces a Media item's file within its family, or writes its alt text, caption, credit and `decorative`: the one way Media is written, from the admin, seeders or imports alike. It does not authorise: the caller checks the actor's Permissions first (ADR 0011).
 *
 * The file's MIME type is read from its content against `kami.media.mimes`, and the stored extension follows it; an image must decode and stay within `kami.media.max_pixels`. Refusals throw Laravel's `ValidationException` on `file`, naming the reason.
 */
final class SaveMedia
{
    /** Closed whatever `kami.media.mimes` says, as families and as MIME types: nothing half-supported gets in (V1). */
    private const array CLOSED_FAMILIES = ['video', 'audio'];

    /** The attributes shared by every Locale. */
    private const array SHARED = ['credit', 'decorative'];

    /** What `fileinfo` reports for an SVG that does not open with `<svg` or an XML declaration. */
    private const array SVG_AS_TEXT = ['text/plain', 'text/xml', 'application/xml'];

    /**
     * @param  UploadedFile|string|null  $file  An upload, or a local path; null to write the metadata of an existing Media item only.
     * @param  array<string, mixed>  $values  `alt` and `caption`, in the Locale written; `credit` and `decorative`, shared by every Locale. A value left out keeps its stored one; `''` clears it (`false` for `decorative`).
     * @param  Media|null  $media  The Media item to update, its file replaced when one is given; a new one when null.
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

        // Trimmed, so text holding only whitespace counts as none, as `Media::missingAlt()` reads it.
        $values = array_map(fn (mixed $value): mixed => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value, $values);
        if (array_key_exists('decorative', $values)) {
            $values['decorative'] ??= false;
        }

        Validator::make($values, [
            'alt' => ['nullable', 'string'],
            'caption' => ['nullable', 'string'],
            'credit' => ['nullable', 'string', 'max:255'],
            'decorative' => ['boolean'],
            ...array_fill_keys(array_keys(Arr::except($values, [...MediaTranslation::ATTRIBUTES, ...self::SHARED])), 'prohibited'),
        ])->validate();

        // The file a replacement leaves behind; none when the new one holds the same bytes.
        $former = $media instanceof Media && $file !== null ? $this->replace($site, $media, $file) : null;
        $media ??= $this->newMedia($site, $file ?? throw new InvalidArgumentException('A new Media item needs a file.'));
        $created = ! $media->exists;

        $id = DB::transaction(function () use ($locale, $values, $media, $file, $created, $former): string {
            foreach (Arr::only($values, self::SHARED) as $attribute => $value) {
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
            if (($created || $former !== null) && $file !== null) {
                // ponytail: an outer transaction rolled back afterwards leaves the new file behind, of a creation or a replacement; M9 cleans up after a failed import.
                Storage::disk(Config::string('kami.media.originals_disk'))->putFileAs(dirname($media->file), is_string($file) ? new File($file) : $file, basename($media->file))
                    ?: throw new RuntimeException("The original of Media [{$media->id}] could not be written.");
            }

            // Once committed, so a rollback keeps what the row still points to. The former Conversions go at once, not at the next
            // prune: Apache would serve the former picture at its former URLs until then (Privacy over Simplicity, ADR 0017).
            if ($former !== null) {
                DB::afterCommit(function () use ($media, $former): void {
                    Storage::disk(Config::string('kami.media.originals_disk'))->delete($former);
                    Conversion::forget($media);
                });
            }

            return $media->id;
        });

        $saved = Media::query()->findOrFail($id);
        // After commit, so a listener never sees a write rolled back; a caller's own transaction defers it to its commit (ADR 0029).
        event(new MediaSaved($saved, $locale, $created, $actor));

        return $saved;
    }

    /**
     * Fills the Media item with what Kami reads from its new file, under a path of its own so the former file stays until commit.
     *
     * @return string|null The former file's path; null when the new file holds the same bytes, which changes nothing.
     *
     * @throws ValidationException When the new file is of another family.
     */
    private function replace(Site $site, Media $media, UploadedFile | string $file): ?string
    {
        $new = $this->newMedia($site, $file);

        if ($new->family !== $media->family) {
            throw $this->refused("family.{$media->family}");
        }

        if ($new->checksum === $media->checksum) {
            return null;
        }

        $former = $media->file;
        $media->forceFill([
            'name' => $new->name,
            'mime' => $new->mime,
            'size' => $new->size,
            'width' => $new->width,
            'height' => $new->height,
            'checksum' => $new->checksum,
            'file' => "media/{$media->id}-" . substr($new->checksum, 0, 8) . '.' . pathinfo($new->file, PATHINFO_EXTENSION),
        ]);

        return $former;
    }

    /**
     * Unsaved, with what Kami reads from the file itself, never from its name but for `name`.
     *
     * @throws ValidationException
     */
    private function newMedia(Site $site, UploadedFile | string $file): Media
    {
        [$path, $name] = is_string($file) ? [$file, $file] : [$file->getRealPath(), $file->getClientOriginalName()];

        if ($path === false || ! is_file($path)) {
            throw new InvalidArgumentException("No readable file at [{$name}].");
        }

        $mime = (string) new finfo(FILEINFO_MIME_TYPE)->file($path);
        if (in_array($mime, self::SVG_AS_TEXT, true) && $this->svg($path) instanceof DOMElement) {
            $mime = 'image/svg+xml';
        }

        if (in_array(Str::before($mime, '/'), self::CLOSED_FAMILIES, true)) {
            throw $this->refused('unsupported', ['type' => $mime]);
        }

        /** @var array<string, list<string>> $allowed */
        $allowed = Arr::except(Config::array('kami.media.mimes'), self::CLOSED_FAMILIES);
        $family = array_find_key($allowed, fn (array $mimes): bool => in_array($mime, $mimes, true))
            ?? throw $this->refused('type', ['type' => $mime]);

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
            'name' => Str::slug(pathinfo($name, PATHINFO_FILENAME)) ?: 'media',
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
            throw $this->refused('undecodable');
        }

        $limit = Config::integer('kami.media.max_pixels');
        if ($limit < $size[0] * $size[1]) {
            throw $this->refused('pixels', ['megapixels' => round($limit / 1_000_000, 3)]);
        }

        $driver = Image::driver();
        if (! $driver instanceof Driver) {
            throw new LogicException('The image driver does not implement ' . Driver::class . '.');
        }

        try {
            return $driver->dimensions((string) file_get_contents($path));
        } catch (Throwable) {
            throw $this->refused('undecodable');
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
        $svg = $this->svg($path) ?? throw $this->refused('undecodable');

        $pixels = fn (string $length): ?int => preg_match('/^\s*(\d+(?:\.\d+)?)\s*(px)?\s*$/', $length, $match) === 1 ? (int) round((float) $match[1]) : null;
        $width = $pixels($svg->getAttribute('width'));
        $height = $pixels($svg->getAttribute('height'));

        if (($width === null || $height === null) && preg_match('/^\s*\S+[\s,]+\S+[\s,]+(\d+(?:\.\d+)?)[\s,]+(\d+(?:\.\d+)?)\s*$/', $svg->getAttribute('viewBox'), $box) === 1) {
            return [(int) round((float) $box[1]), (int) round((float) $box[2])];
        }

        return [$width, $height];
    }

    /** The root `<svg>` element of a well-formed SVG document; null for anything else. Network access stays off while parsing. */
    private function svg(string $path): ?DOMElement
    {
        $document = new DOMDocument;
        $root = @$document->load($path, LIBXML_NONET) ? $document->documentElement : null;

        return $root?->localName === 'svg' ? $root : null;
    }

    /** @param  array<string, string|int|float>  $replace */
    private function refused(string $reason, array $replace = []): ValidationException
    {
        return ValidationException::withMessages(['file' => __("kami::admin.media.refused.{$reason}", $replace)]);
    }
}
