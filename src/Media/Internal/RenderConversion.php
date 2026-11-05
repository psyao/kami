<?php

declare(strict_types=1);

namespace Psyao\Kami\Media\Internal;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Psyao\Kami\Media\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Mime\MimeTypes;

/**
 * `/media/{ulid}/{v}/{preset}-{width}.{format}`: generates the Conversion on its first request and serves it with the headers
 * Apache sends once the file is on disk (ADR 0017). Anything not declared, or a stale `{v}`, is a 404 that generates nothing.
 *
 * @internal
 */
final class RenderConversion
{
    public function __invoke(string $media, string $version, string $file): BinaryFileResponse
    {
        // No leading zero: one URL per file, so Apache serves every later request.
        if (preg_match('/^([a-z][a-z0-9-]*)-([1-9]\d{0,5})\.([a-z0-9]+)$/', $file, $match) !== 1) {
            abort(404);
        }

        $model = Media::query()->find($media);
        $conversion = $model instanceof Media ? Conversion::find($model, $match[1], (int) $match[2], $match[3]) : null;

        if (! $conversion instanceof Conversion || $conversion->version() !== $version) {
            abort(404);
        }

        try {
            $path = $conversion->write();
        } catch (LockTimeoutException) {
            abort(503, headers: ['Retry-After' => '5']);
        }

        return response()->file($path, [
            'Content-Type' => MimeTypes::getDefault()->getMimeTypes($conversion->format)[0],
            'Cache-Control' => PublicMedia::HEADERS['Cache-Control'],
        ]);
    }
}
