<?php

declare(strict_types=1);

namespace Psyao\Kami\Media;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LogicException;
use Psyao\Kami\Media\Internal\Conversion;
use Psyao\Kami\Media\Internal\ImagePresets;
use Psyao\Kami\Media\Internal\MediaTranslation;
use Psyao\Kami\Sites\Internal\Site;

/**
 * An image or a document of the Site's library (ADR 0017), written through `SaveMedia`. Its alt text and caption are per Locale; the file, the credit and `decorative` are shared (ADR 0013).
 *
 * @property-read string $id
 * @property-read string $site
 * @property-read string $name The original filename, slugged, without its extension.
 * @property-read string $mime The MIME type read from the file's content.
 * @property-read string $family `image` or `document`.
 * @property-read int $size In bytes.
 * @property-read int|null $width In pixels; null for a document, and for an SVG that states no size.
 * @property-read int|null $height In pixels; null for a document, and for an SVG that states no size.
 * @property-read string $checksum The original's SHA-256.
 * @property-read string|null $credit
 * @property-read bool $decorative Read through `isDecorative()`.
 * @property-read string $file Its path on `kami.media.originals_disk`.
 * @property-read Carbon|null $created_at
 * @property-read Carbon|null $updated_at
 */
#[Table(name: 'kami_media')]
final class Media extends Model
{
    use HasUlids;

    /** @internal */
    #[\Override]
    protected $with = ['translations'];

    /**
     * @internal
     *
     * @return HasMany<MediaTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(MediaTranslation::class, 'media_id');
    }

    public function isImage(): bool
    {
        return $this->family === 'image';
    }

    /** Marked as adding nothing to the page: rendered with `alt=""`, never reported as missing its alt text. */
    public function isDecorative(): bool
    {
        return (bool) $this->decorative;
    }

    /**
     * The absolute URL of a Conversion: an Image Preset at one of its widths, in a format; for a document or an SVG, of its own file.
     *
     * A document or an SVG takes no preset, or `file`, and is served unchanged at `/media/{id}/{v}/{name}.{ext}`. For an image, without a preset, the `original` one; without a width, the preset's largest; without a format, the one every browser
     * reads (JPEG, or PNG for any other original). AVIF and WebP only where this host encodes them, and never for `kami-social`.
     *
     * @throws InvalidArgumentException For a preset, width or format not declared for this Media item, or not offered on this host; for `file` on a raster image.
     * @throws LogicException For another preset on a document or an SVG, which is never converted.
     */
    public function url(?string $preset = null, ?int $width = null, ?string $format = null): string
    {
        if (! Conversion::isConvertible($this)) {
            return match (true) {
                ! in_array($preset, [null, ImagePresets::FILE], true) => throw new LogicException("Media [{$this->id}] is a document or an SVG: it has no Conversion."),
                $width !== null || $format !== null => throw new InvalidArgumentException("Media [{$this->id}] is served as its own file: it takes no width or format."),
                default => Conversion::fileUrl($this),
            };
        }

        if ($preset === ImagePresets::FILE) {
            throw new InvalidArgumentException("Media [{$this->id}] is a raster image: its original is never served, only its Conversions.");
        }

        $presets = resolve(ImagePresets::class);
        $declared = $presets->find($preset ??= ImagePresets::ORIGINAL) ?? throw new InvalidArgumentException("No Image Preset [{$preset}] is declared.");
        $width ??= max([0, ...$presets->widths($declared, $this)]);
        $format ??= Conversion::fallbackFormat($this);

        return Conversion::find($this, $preset, $width, $format)?->url()
            ?? throw new InvalidArgumentException("The Image Preset [{$preset}] has no Conversion of Media [{$this->id}] {$width} pixels wide in {$format}.");
    }

    /**
     * Images that need alt text and have none: not decorative, with a blank alt text in the Site's default Locale, whatever the others hold.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function missingAlt(Builder $query): void
    {
        $query->where('family', 'image')
            ->where('decorative', false)
            ->whereDoesntHave('translations', fn (Builder $translation) => $translation
                ->where('locale', Site::current()->defaultLocale())
                // Standard SQL on every engine; `SaveMedia` stores no empty string, but may store spaces.
                ->whereRaw("TRIM(alt) <> ''"));
    }

    /** In the current Locale, or the default Locale's when blank there; null when both are. */
    public function alt(): ?string
    {
        return $this->translated('alt')[0];
    }

    /**
     * @internal
     *
     * The Locale `alt()` reads from, so `<x-kami::image>` marks a fallback with `lang`; null when it reads none.
     */
    public function altLocale(): ?string
    {
        return $this->translated('alt')[1];
    }

    /** In the current Locale, or the default Locale's when blank there; null when both are. */
    public function caption(): ?string
    {
        return $this->translated('caption')[0];
    }

    /** @return array{string, string}|array{null, null} The value and its Locale. */
    private function translated(string $attribute): array
    {
        $site = Site::current();

        foreach (array_unique([$site->currentLocale(), $site->defaultLocale()]) as $locale) {
            $value = $this->translations->firstWhere('locale', $locale)?->getAttribute($attribute);

            if (is_string($value) && trim($value) !== '') {
                return [$value, $locale];
            }
        }

        return [null, null];
    }

    protected function casts(): array
    {
        return ['size' => 'integer', 'width' => 'integer', 'height' => 'integer', 'decorative' => 'boolean'];
    }
}
