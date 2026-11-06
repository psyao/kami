<?php

declare(strict_types=1);

namespace Psyao\Kami\Media\Internal;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Psyao\Kami\Media\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Mime\MimeTypes;
use Throwable;

/**
 * `/media/{ulid}/{v}/{preset}-{width}.{format}`: generates the Conversion on its first request and serves it with the headers
 * Apache sends once the file is on disk (ADR 0017); `/media/{ulid}/{v}/{name}.{ext}` copies a document's or an SVG's file unchanged. Anything not declared, or a stale `{v}`, is a 404 that generates nothing.
 * An AVIF or WebP Conversion that fails to generate redirects to the fallback format, which every browser reads.
 *
 * @internal
 */
final class RenderConversion
{
    public function __invoke(string $media, string $version, string $file): BinaryFileResponse | RedirectResponse
    {
        $model = Media::query()->find($media);

        if ($model instanceof Media && ! Conversion::isConvertible($model)) {
            return $this->file($model, "media/{$media}/{$version}/{$file}");
        }

        // No leading zero: one URL per file, so Apache serves every later request.
        $conversion = $model instanceof Media && preg_match('/^([a-z][a-z0-9-]*)-([1-9]\d{0,5})\.([a-z0-9]+)$/', $file, $match) === 1
            ? Conversion::find($model, $match[1], (int) $match[2], $match[3])
            : null;

        if (! $conversion instanceof Conversion || $conversion->version() !== $version) {
            abort(404);
        }

        try {
            // The file was replaced since this request read it: its version is stale.
            $path = $conversion->write() ?? abort(404);
        } catch (LockTimeoutException) {
            abort(503, headers: ['Retry-After' => '5']);
        } catch (Throwable $exception) {
            $fallback = $conversion->fallback();

            if ($fallback->format === $conversion->format) {
                throw $exception;
            }

            report($exception);

            // Laravel's own `no-cache`, never the immutable one: the browser asks again, and the next request retries.
            return redirect()->to($fallback->url());
        }

        return response()->file($path, [
            'Content-Type' => MimeTypes::getDefault()->getMimeTypes($conversion->format)[0],
            'Cache-Control' => PublicMedia::HEADERS['Cache-Control'],
        ]);
    }

    /** A document or an SVG at its one URL, unchanged; any other name, extension or version is a 404 that writes nothing. */
    private function file(Media $media, string $requested): BinaryFileResponse
    {
        // The file was replaced since this request read it: its version is stale.
        $path = $requested === Conversion::filePath($media) ? Conversion::writeFile($media) : null;

        return response()->file($path ?? abort(404), [
            'Content-Type' => $media->mime,
            'Cache-Control' => PublicMedia::HEADERS['Cache-Control'],
        ]);
    }
}
