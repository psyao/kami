<?php

declare(strict_types=1);

namespace Psyao\Kami\Media\Internal;

use Illuminate\Contracts\Image\Transformation;
use Imagick;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * Drops EXIF, GPS and every other profile from a Conversion (principle 2). GD never writes them back; Imagick keeps them unless stripped.
 *
 * @internal
 */
final class StripMetadata implements Transformation
{
    /** The handler per `Image` driver; a driver Kami does not know fails on the transformation rather than leak metadata. */
    public static function handle(ImageInterface $image): ImageInterface
    {
        $native = $image->core()->native();

        if ($native instanceof Imagick) {
            $native->stripImage();
        }

        return $image;
    }
}
