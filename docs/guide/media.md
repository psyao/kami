# Media

Each Site has one Media library: images and documents, stored once and referenced wherever they are used. A Media item is an entity of its own, not an attachment of an Entry. Its file, credit and `decorative` switch are shared by every Locale; its alt text and caption are per Locale ([Multilingual](multilingual.md)).

## Storing a file

`SaveMedia` stores a file in the library, from an upload or a local path, the same way from a controller, a seeder or a command. It does not check Permissions: the caller does ([Users and roles](users-and-roles.md)).

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
| Video and audio | not supported yet, whatever the configuration says |
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

A value left out keeps its stored one, and `''` clears it. Any other key is refused. Alt text is never required here: an upload, a seeder or an import may leave it missing, to be filled in later.

## Reading Media

`Media` is an Eloquent model of the library:

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

## Configuration

The `media` key of `config/kami.php`:

```php
'media' => [
    'originals_disk' => 'local',
    'mimes' => [
        'image' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/svg+xml'],
        'document' => ['application/pdf'],
    ],
    'max_pixels' => 40_000_000,
],
```

- `originals_disk` takes any disk of `config/filesystems.php`, local or remote, such as an S3-compatible bucket. Keep it private: originals keep their metadata.
- `mimes` lists the types accepted per family, as `fileinfo` reports them. Add a type to a family to accept it, for instance `'text/plain'` under `document`. The `video` and `audio` families stay closed.
- `max_pixels` caps an image's width × height. A server with more memory than shared hosting can raise it.

## Reacting to Media

`SaveMedia` dispatches `Psyao\Kami\Media\Events\MediaSaved` once its write is committed, with `media` (as saved), `locale` (the Locale written), `created` and `actor`, like the [Content events](publishing.md#reacting-to-content).
