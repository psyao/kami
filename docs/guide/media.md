# Media

Each Site has one Media library: images and documents, stored once and referenced wherever they are used. A Media item is an entity of its own, not an attachment of an Entry. Its file, credit and `decorative` switch are shared by every Locale; its alt text and caption are per Locale ([Multilingual](multilingual.md)).

## Storing a file

`SaveMedia` stores a file in the library, from an upload or a local path, the same way from a controller, a seeder or a command. It does not check Permissions: the caller does ([Users and roles](users-and-roles.md)). Nor does it restrict a path: it reads any local file the PHP process can, so never pass it a path a visitor chose.

```php
use Psyao\Kami\Media\Actions\SaveMedia;

$media = app(SaveMedia::class)->handle(storage_path('import/team.jpg'), ['alt' => 'The team on the terrace']);

$media = app(SaveMedia::class)->handle($request->file('photo'), actor: $request->user());
```

Kami reads everything from the file itself:

- **Type.** The MIME type comes from the file's content, never from its name or what the browser claims, and the stored extension follows it: a PNG uploaded as `photo.jpg` is stored as a PNG, and a script renamed `photo.jpg` is refused.
- **Name.** The original filename, slugged, without its extension: `Team Photo.JPG` becomes `team-photo`.
- **Size, dimensions and checksum.** The size in bytes, the width and height in pixels (`null` for a document), and the SHA-256 of the file.

A refused file throws Laravel's `ValidationException` on `file`, naming the reason, and stores nothing:

| Refused | Because |
| --- | --- |
| A type outside the [allowlist](#configuration) | `kami.media.mimes` does not list it |
| Video and audio | not supported yet, whatever family the configuration lists them under |
| An image that does not decode | the file is damaged, or is not the image it claims to be |
| An image over the pixel limit | it would cost too much to resize on the host: 40 megapixels by default |

An SVG is never decoded as a picture: its dimensions come from its `width` and `height` in pixels, or from its `viewBox`, and are `null` when it states neither.

The original is kept on `kami.media.originals_disk`, private by default, with its metadata (EXIF, GPS) intact. Visitors are never served the original.

## Alt text, caption and credit

`SaveMedia` writes the alt text and caption in one Locale, the default one unless its fourth argument names another. Pass the Media item as the third argument to change its text without touching its file:

```php
$media = app(SaveMedia::class)->handle(null, ['alt' => 'L’équipe sur la terrasse'], $media, 'fr');
$media = app(SaveMedia::class)->handle(null, ['credit' => 'Ada Lovelace', 'decorative' => true], $media);
```

| Value | Scope |
| --- | --- |
| `alt` | per Locale |
| `caption` | per Locale |
| `credit` | shared by every Locale, up to 255 characters |
| `decorative` | shared: the image adds nothing to the page, and is rendered with an empty `alt` |

A value left out keeps its stored one. Text is trimmed, and `''` or whitespace alone clears it. Any other key is refused. Alt text is never required here: an upload, a seeder or an import may leave it missing, to be filled in later. Only the admin's edit form asks for it ([Editing in the admin](#editing-in-the-admin)).

An image is missing its alt text when it is not decorative and its alt text in the Site's default Locale is blank, whatever the other Locales hold. The `missingAlt` scope finds them:

```php
Media::query()->missingAlt()->count();
```

A missing alt text never blocks anything, but it stays in sight:

- **Publishing** a Translation, from its form or the list's bulk **Publish**, and saving one that is published or Scheduled, warn of the images its Media Fields show without alt text, by name, on a message kept until dismissed. A Draft is never warned about.
- **`kami:doctor`** counts the images missing their alt text as a warning, which the dashboard's **Needs attention** shows after its next run.
- The library's **Alt text missing** filter lists them ([The library in the admin](#the-library-in-the-admin)).

## Replacing a file

Pass a file and the Media item to replace its file. The item keeps its id, so every Entry referencing it keeps it, along with its alt text, caption, credit and `decorative`:

```php
$media = app(SaveMedia::class)->handle($request->file('photo'), media: $media, actor: $request->user());
```

The new file goes through the same checks as an upload, and must be of the same family: an image replaces an image, a document a document. Another family is refused on `file`, changing nothing. Kami reads the name, type, size, dimensions and checksum from the new file, so the Conversions get new URLs ([URLs](#urls)): browsers and caches never keep the former picture. Once the change is committed, the former original and every former Conversion are deleted at once rather than left to `kami:media:prune`, so the former picture stops being served at its former URLs straight away. A file holding the same bytes as the current one changes nothing.

## Deleting a Media item

`DeleteMedia` deletes a Media item for good: its row, its alt text and caption in every Locale, its original and every file served from it. There is no Media trash.

```php
app(DeleteMedia::class)->handle($media, actor: $request->user());
```

It refuses while any Entry uses the item through a [Media Field](content-types.md#media), an Entry in the Trash too: the `ValidationException` is on `media`, and its message names those Entries, marking the ones in the Trash. Remove the item from them, or delete those in the Trash for good, then delete it. The files go once the deletion is committed, at once rather than at the next `kami:media:prune`, so nothing of the item stays served; a transaction rolled back around it keeps them.

## The library in the admin

The admin's **Media** page lists the library, newest first, with a thumbnail of each raster image, or an SVG's own file, its name, type, size and alt text state: **Described**, **Missing** (an image, not decorative, with no alt text in the default Locale), **Decorative**, or **Not needed** for a document. A filter narrows it to images or documents. Opening it takes `kami.media.view` ([Users and roles](users-and-roles.md#permissions)).

**Upload** takes up to 20 files at once, dropped onto the form or picked from the computer, under `kami.media.create`. Each one goes through `SaveMedia`: the files it accepts are saved, and those it refuses are listed with their reason in a message kept until dismissed, without costing the others. Twenty files per upload keeps the request within shared hosting's time limit, as each image is decoded in full to read its dimensions.

Each file may weigh up to the lowest of PHP's `upload_max_filesize` and `post_max_size` and Livewire's rule for temporary uploads, `livewire.temporary_file_upload.rules`, whose default is 12 MB. The form states that limit, and refuses a larger file before it is sent. To let PHP's limit alone decide, publish Livewire's configuration and drop `max` from the rule:

```php
// config/livewire.php
'temporary_file_upload' => [
    'rules' => ['required', 'file'],
],
```

Each image's `admin-thumb` Conversion is generated once the upload's response is sent, so the editor does not wait for it. One that fails is reported to the log and generated on its first request instead.

A filter, **Alt text missing**, narrows the list to the images [missing their alt text](#alt-text-caption-and-credit).

The list searches by name. The admin's global search finds Media items by name, or by alt text in any Locale, under `kami.media.view`: a result opens the edit page under `kami.media.update`, and the list searched for its name otherwise.

### Editing in the admin

Opening a row edits the Media item, under `kami.media.update`. The page edits the alt text and caption of one Locale, with the same Locale switcher as an Entry's edit page: the default Locale at `/admin/media/{id}/edit`, another at `/admin/media/{id}/edit/fr`. Each Locale shows its own values only, never the default Locale's fallback. The credit and **Decorative** are shared by every Locale.

Saving an image in the default Locale requires its alt text, unless it is marked decorative: describing images is part of the job. Other Locales, documents and uploads never require it.

**Replace file** takes a new file of the same family through `SaveMedia` ([Replacing a file](#replacing-a-file)). The form states the upload limit, and a file of another family is refused with its reason. The new `admin-thumb` is generated once the response is sent.

**Used by** lists the first 50 Entries using the item, then how many more, each linked to its edit page. One in the Trash is marked; it, and one of a type the editor cannot open, are named without a link. **Delete**, under `kami.media.delete`, goes through `DeleteMedia` ([Deleting a Media item](#deleting-a-media-item)): an item in use is refused on a message naming its Entries.

## Reading Media

`Media` is an Eloquent model of the library. An Entry links it through a [Media Field](content-types.md#media), which loads it with its Translations:

```php
use Psyao\Kami\Media\Media;

$media = Media::query()->find($id);

$media->alt();          // in the current Locale, or the default Locale's when blank there; null when both are
$media->caption();      // the same fallback
$media->isImage();      // image or document
$media->isDecorative();
$media->name;           // team-photo
$media->mime;           // image/jpeg
$media->size;           // in bytes
$media->width;          // in pixels, null for a document
$media->height;
$media->credit;
```

## Showing an image

`<x-kami::image>` renders a Media item through an [Image Preset](#image-presets):

```blade
<x-kami::image :media="$entry->cover" preset="card" />
```

```html
<picture class="kami-image">
    <source type="image/avif" srcset="…/card-320.avif 320w, …/card-640.avif 640w" sizes="(min-width: 60rem) 33vw, 100vw">
    <source type="image/webp" srcset="…/card-320.webp 320w, …/card-640.webp 640w" sizes="(min-width: 60rem) 33vw, 100vw">
    <img src="…/card-640.jpg" srcset="…/card-320.jpg 320w, …/card-640.jpg 640w" sizes="(min-width: 60rem) 33vw, 100vw"
         width="640" height="480" alt="Our team on the lake shore" loading="lazy" decoding="async">
</picture>
```

| Prop | Effect |
| --- | --- |
| `media` | A `Media` item, or null: the component then renders nothing, so an empty optional Field needs no `@if`. |
| `preset` | The Image Preset's handle; one not declared throws. |
| `sizes` | Replaces the preset's `sizes`. Without either, the attribute is left out. |
| `eager` | For the image that is the page's largest paint, such as a hero: drops `loading="lazy"` and adds `fetchpriority="high"`. |

Any other attribute, such as `class` or `id`, goes on the `<img>`; the `<picture>` carries `kami-image`, and no CSS. What it prints:

- **a `<source>` per format** this host encodes, AVIF then WebP, and the `<img>` in the [fallback format](#formats);
- **a `srcset`** of the preset's widths up to the original's, or up to the narrower width its ratio crops a wider original to, never upscaled; when none fits, the smallest alone, at that width;
- **`width` and `height`** from the preset's ratio, or the image's own proportions, so the page does not shift while it loads;
- **the alt text** in the current Locale. When it falls back to the default Locale's, the `<img>` carries `lang` with that Locale, so assistive technology reads it in the right language. A decorative image, and one missing its alt text, get `alt=""`: the component never makes one up from the filename. Publishing warns of the images shown without one, `kami:doctor` counts them, and the library's **Alt text missing** filter lists them ([Alt text, caption and credit](#alt-text-caption-and-credit)).

It runs no query when the Media item and its Translations are loaded, as a [Media Field](content-types.md#media) loads them: a listing of covers costs the queries of its Entries alone. A document renders nothing.

An SVG is never rasterised: its `<picture>` holds a plain `<img>` of [its own file](#documents-and-svg), with no `<source>` or `srcset`, `width` and `height` when the SVG states its size, and the same alt text rules. The preset must still be declared; it shapes nothing.

## Conversions

Visitors never receive an original. They receive Conversions: an image re-encoded at one width, in one format, through an Image Preset. A Conversion is generated on the first request for it, written to `kami.media.public_disk` under `media/`, and served as a static file from then on ([ADR 0017](../adr/0017-conversions-generated-on-first-request.md)). `kami:install` links `public/media` to that directory, so Apache serves every later request without PHP ([Hosting](hosting.md#conversions)). Every Conversion is:

- **oriented** as the camera's EXIF says, then stripped of all metadata: no EXIF, no GPS, no camera;
- **cropped** to the preset's ratio around the centre, when it has one;
- **never upscaled**: a width above the original's gives the original's;
- **in one [format](#formats)**: AVIF, WebP or the fallback format.

An SVG or a document has no Conversion: it is [served as its own file](#documents-and-svg).

### Formats

Conversions are offered in AVIF, then WebP, then the fallback format, which every browser reads: JPEG for a JPEG original, PNG for any other. AVIF and WebP are offered only where this host really encodes them. For each, Kami encodes a 1 × 1 image and reads it back, through the `images.default` driver first, then the other one (GD or Imagick), and generates that format with the first driver that passes. The result is cached for a day on the default cache store, apart for the command line and the web, which may load different extensions, and read once per request.

A host where neither driver encodes AVIF or WebP offers the fallback format alone: a URL in either answers 404 and generates nothing, and every image still shows. The fallback format is never tested.

AVIF costs the most to encode. Set `kami.media.avif` to `false` to offer WebP and the fallback format only; it applies on the next request, without clearing the cache.

When an AVIF or WebP Conversion fails to generate, Kami reports the error, writes nothing, and answers 302 to the fallback-format Conversion at the same width, without the immutable cache: the next request for it tries again. A failure of the fallback format itself answers 500.

`kami:doctor` lists each format with the driver that encodes it on the command line, `not available`, or `off (kami.media.avif)`.

### Image Presets

A preset names a way of showing images. List yours in `config/kami.php`:

```php
use Psyao\Kami\Media\ImagePreset;

'media' => [
    'presets' => [
        ImagePreset::make('card')->ratio(4, 3)->widths([320, 640, 960, 1280])->sizes('(min-width: 60rem) 33vw, 100vw'),
        ImagePreset::make('content')->widths([640, 1280]),
    ],
],
```

| Method | Effect |
| --- | --- |
| `make($handle)` | Lowercase letters and digits, words joined by hyphens: `card`, `hero-wide`. It appears in the URL. |
| `ratio($width, $height)` | Crops every Conversion to this ratio around the centre: `ratio(4, 3)`, `ratio(1.91, 1)`. Without it, each image keeps its own proportions. |
| `widths([...])` | The only widths generated, in pixels. |
| `sizes($sizes)` | The default `sizes` of the image's `srcset`. |

Kami ships three presets, whose handles yours cannot take:

| Handle | Conversions |
| --- | --- |
| `admin-thumb` | 1:1, 320 wide, for the admin |
| `original` | the image's own proportions, at its own width |
| `kami-social` | 1.91:1, 1200 wide, in the fallback format only, for social cards: link previews often read nothing else |

`file` is reserved too, for [documents and SVG](#documents-and-svg). A preset with no width, a width outside 1 to 999,999 pixels, a ratio side that is not positive, a malformed handle or a handle used twice fails the first time something reads the presets, with a message naming it, as an invalid [Content Type](content-types.md#handle) does.

### URLs

`url()` gives the absolute URL of a Conversion:

```php
$media->url('card', 640);   // https://example.com/media/01jd…/3f9a0c1e/card-640.jpg
$media->url('card', 640, 'avif');   // …/card-640.avif, where this host encodes AVIF
$media->url('card');        // the preset's largest width
$media->url();              // the original preset
```

A preset, width or format not declared for the Media item, or a format this host does not offer, throws `InvalidArgumentException`. A document's or an SVG's `url()` is the URL of [its own file](#documents-and-svg).

The URL is `/media/{id}/{version}/{preset}-{width}.{format}`. The version is a short hash of the original file and the preset's ratio: changing either gives new URLs, so a browser or a cache never keeps a stale image, and nothing needs purging. Adding or removing a width leaves the other widths' URLs as they are. The former files stay on disk until [`kami:media:prune`](#pruning-stale-files).

Kami answers 404, and generates nothing, for any Media item, preset, width or format not declared or not offered, and for a stale version: nobody can use the site to resize images. Two first requests for the same Conversion generate it once, under a lock of the default cache store; the second one waits and serves the file the first wrote, or answers 503 after 50 seconds. A Conversion carries `Cache-Control: public, max-age=31536000, immutable` and the [front's security headers](security.md#kamis-pages), and no session or cookie.

`media` is reserved: no Prefix or Path starts with it ([Content Types](content-types.md#prefix)).

### Documents and SVG

A document, such as a PDF, and an SVG are served unchanged, byte for byte, under the name they were uploaded with, slugged:

```php
$brochure->url();           // https://example.com/media/01jd…/5e0c41a7/annual-report.pdf
$brochure->url('file');     // the same
```

The URL is `/media/{id}/{version}/{name}.{extension}`, the version a short hash of the file. As for a Conversion, the first request copies the original to `kami.media.public_disk` under `media/`, then Apache serves it as a static file with the same headers: its MIME type, `nosniff` and the immutable cache. An SVG also carries a `Content-Security-Policy` that runs no script and loads nothing, so an SVG opened directly cannot attack its visitor, and is never decoded by GD or Imagick: it stays sharp at any size. Replacing the file gives it a new URL, under its new name, and stops the former one at once ([Replacing a file](#replacing-a-file)).

Another name, extension or version answers 404 and writes nothing. `file` is not a preset of its own, but its handle is reserved: a preset named `file` fails like one reusing Kami's. A raster image is never served as its own file, since its original keeps its metadata: `url('file')` throws `InvalidArgumentException` for it, and any other preset on a document or an SVG throws `LogicException`.

## Pre-generating Conversions

A Conversion costs its first visitor the time to generate it. After a migration or an import, generate them beforehand, over SSH:

```bash
php artisan kami:media:warm
```

It writes every declared Conversion of every Media item, at each width, in each format this host encodes, and copies each document's and SVG's own file, with a progress bar. A file already there is skipped, so a run cut short resumes where it stopped when you start it again. It ends with a count: `4 Media items: 12 files written, 0 already there.`

| Option | Effect |
| --- | --- |
| `--preset=card` | Only this preset; repeat it for several. `--preset=file` copies the files of documents and SVG; with any other preset, they are skipped. An undeclared preset fails before anything is written. |
| `--entry=01jd…` | Only the Media items this Entry uses, in any Locale, as a Draft or in the Trash too. |

A Conversion that fails to generate is reported, as the web reports it, and the others go on; the command lists each failure and exits with 1. The formats are those the command line encodes: the web may encode one more, which it then generates on its first request ([Formats](#formats)).

On shared hosting, run it over SSH, whose PHP has no time limit; each Conversion still takes the memory of one image. Nothing needs it: a Conversion it did not write is generated on its first request.

## Pruning stale files

Files stay on the public disk when a preset, a width or a format is removed, or when `kami.media.avif` is turned off. Delete them with:

```bash
php artisan kami:media:prune
```

It deletes, under `media/`, every file no Media item serves any more: another version, a preset, width or format no longer declared, a document's former name, and whatever is left of a deleted item. It keeps AVIF and WebP Conversions whether or not this host encodes them, as long as `kami.media.avif` allows the format: the web may encode one the command line does not. It also deletes what an interrupted write left in `.kami-tmp/`, after an hour. Files at the root of `media/`, such as its `.htaccess`, are never touched. It ends with a count: `Deleted 3 files.`

It walks one Media item at a time, so a run cut short is simply started again. Nothing visible depends on it: a site that never prunes only uses more disk. Replacing or deleting a Media item already deletes its former files at once ([Replacing a file](#replacing-a-file)). Run it after changing the presets, or from a scheduler: Kami does not schedule it yet.

## Configuration

The `media` key of `config/kami.php`:

```php
'media' => [
    'presets' => [],
    'originals_disk' => 'local',
    'public_disk' => 'public',
    'mimes' => [
        'image' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/svg+xml'],
        'document' => ['application/pdf'],
    ],
    'max_pixels' => 40_000_000,
    'avif' => true,
],
```

- `presets` lists the site's [Image Presets](#image-presets).
- `public_disk` is the local disk Conversions are written to, under `media/`.
- `originals_disk` takes any disk of `config/filesystems.php`, local or remote, such as an S3-compatible bucket. Keep it private: originals keep their metadata.
- `mimes` lists the types accepted per family, as `fileinfo` reports them. Add a type to a family to accept it, for instance `'text/plain'` under `document`. The `video` and `audio` families stay closed.
- `max_pixels` caps an image's width × height. A server with more memory than shared hosting can raise it.
- `avif` set to `false` stops offering AVIF ([Formats](#formats)).

## Reacting to Media

`SaveMedia` dispatches `Psyao\Kami\Media\Events\MediaSaved` once its write is committed, with `media` (as saved), `locale` (the Locale written), `created` and `actor`, like the [Content events](publishing.md#reacting-to-content). `DeleteMedia` dispatches `Psyao\Kami\Media\Events\MediaDeleted` once its deletion is committed, with `mediaId`, `site` and `actor`: the row is gone, so it carries values only.
