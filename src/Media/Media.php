<?php

declare(strict_types=1);

namespace Psyao\Kami\Media;

use Illuminate\Database\Eloquent\Attributes\Table;
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
     * The absolute URL of a Conversion: an Image Preset at one of its widths, in a format.
     *
     * Without a preset, the `original` one; without a width, the preset's largest; without a format, the one every browser
     * reads (JPEG, or PNG for any other original).
     *
     * @throws InvalidArgumentException For a preset, width or format not declared for this Media item.
     * @throws LogicException For a document or an SVG, which is never converted.
     */
    public function url(?string $preset = null, ?int $width = null, ?string $format = null): string
    {
        // ponytail: raster images only; #300 gives documents and SVG the URL of their file.
        if (! Conversion::isConvertible($this)) {
            throw new LogicException("Media [{$this->id}] is a document or an SVG: it has no Conversion.");
        }

        $presets = resolve(ImagePresets::class);
        $declared = $presets->find($preset ??= ImagePresets::ORIGINAL) ?? throw new InvalidArgumentException("No Image Preset [{$preset}] is declared.");
        $width ??= max([0, ...$presets->widths($declared, $this)]);
        $format ??= Conversion::fallbackFormat($this);

        return Conversion::find($this, $preset, $width, $format)?->url()
            ?? throw new InvalidArgumentException("The Image Preset [{$preset}] has no Conversion of Media [{$this->id}] {$width} pixels wide in {$format}.");
    }

    /** In the current Locale, or the default Locale's when blank there; null when both are. */
    public function alt(): ?string
    {
        return $this->translated('alt');
    }

    /** In the current Locale, or the default Locale's when blank there; null when both are. */
    public function caption(): ?string
    {
        return $this->translated('caption');
    }

    private function translated(string $attribute): ?string
    {
        $site = Site::current();

        foreach (array_unique([$site->currentLocale(), $site->defaultLocale()]) as $locale) {
            $value = $this->translations->firstWhere('locale', $locale)?->getAttribute($attribute);

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    protected function casts(): array
    {
        return ['size' => 'integer', 'width' => 'integer', 'height' => 'integer', 'decorative' => 'boolean'];
    }
}
