# Image conversions are generated on first request, from named presets, as static files

An image Media is never served from its original. The page asks for a Conversion of a named Image Preset, at a URL of the form `/media/{ulid}/{v}/{preset}-{width}.{format}`. On the first request the file is missing, the stock `public/.htaccess` (`!-f`) hands the request to Laravel, and Kami generates that one Conversion through Laravel's `Image` facade (Intervention Image 4), writes it to the public disk and serves it. From then on Apache serves the file without PHP. `{v}` is a short hash of the original file, the Focal point and the preset's definition, so any change to one of them gives a new URL. Files can be cached as `immutable`, and nothing has to be regenerated.

Developers declare presets in code (ratio, widths, default `sizes`). The route rejects with a 404 any preset, width, format or Media that is not declared or not supported. The URL space is finite (Media × presets × widths × formats), so URLs carry no signature and the server cannot be used to resize images in bulk.

The decision trades a first-view cost against both serving cost and upload cost. Velox generated every variant at upload: sea's 1,105 photos took about 8 minutes and produced 3,816 files (324 MB), whether or not they were ever displayed, and any change to the variant list meant regenerating the whole library (retrospective §2.2). Glide generates on demand but keeps PHP on every request. Generating on first request keeps the "no PHP to serve an image" property Velox was right about (principle 7). Upload stays cheap, and the queue stays out of visible behaviour (shared hosting).

## Considered options

- **spatie/laravel-medialibrary.** Its `media` table is polymorphic and attached to a model, which contradicts Media as an entity of its own referenced through `kami_references` (ADRs 0002, 0005). It queues conversions by default and optimises them through `proc_open`, which is disabled on the web. Its image engine is `spatie/image`, not Intervention, and its latest version requires PHP 8.4.
- **Glide 4, restricted to presets.** Only its transformation engine would be used, and Laravel's `Image` facade already provides one. Its model (URL parameters, a Flysystem cache served by PHP) would have to be worked around.
- **Signed free parameters.** More flexible for the developer, but the signing key becomes a rendering secret, and every signed combination adds files to the disk.
- **awcodes/filament-curator.** It requires Intervention 3, which cannot coexist with Laravel 13's `Image` facade.

## Consequences

- One Conversion per request, under a per-file lock (`file` or `database` cache store). If generation fails (memory, a broken format), the route answers with a 302 to the fallback-format Conversion at the same width, logs the error and writes nothing, so a later request retries. Uploads above a pixel limit (40 MP by default, configurable) are refused, which caps the cost of a single generation within the 60 s web budget.
- Formats are chosen per driver by a real encoding test (encode a small image, decode it back), not by `queryFormats()`. The result is cached and reported by `kami:doctor`. `<x-kami::image>` emits a `<picture>` with AVIF, then WebP, then the original's format family, and leaves out any format that is unavailable.
- Every Conversion is re-encoded with orientation applied and no metadata (no EXIF, no GPS): principle 2. Originals stay on the private disk and keep their EXIF.
- The web pre-generates only `admin-thumb`, through `defer()` after the upload response. `kami:media:warm` pre-generates from the CLI for imports and migrations. `kami:media:prune` (CLI, and weekly in the scheduler) deletes stale Conversions. If the scheduler never runs, the disk grows but nothing visible breaks.
- The public disk must be local for the `!-f` fallback to work. Remote storage for Conversions is a V1 door: URLs are built by a single class, so a remote URL builder can replace it. A remote disk for originals already works.
- On a server behind nginx, the rewrite and the `public/media` headers must be reproduced. `kami:doctor` checks for them.
