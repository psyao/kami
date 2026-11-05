<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Psyao\Kami\Media\Media;

uses(RefreshDatabase::class);

/**
 * Nests the `files[]` part of Livewire's upload request, as PHP does.
 *
 * ponytail: pest-plugin-browser's server keeps a multipart name such as `files[]` flat, so Livewire would see no file;
 * V1 at the latest, sooner once the plugin nests them as PHP does.
 */
final class NestUploadedFiles
{
    public function handle(Request $request, Closure $next): mixed
    {
        if ($request->files->has('files[]')) {
            $request->files->set('files', [$request->files->get('files[]')]);
            $request->files->remove('files[]');
        }

        return $next($request);
    }
}

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    // Where Livewire keeps temporary uploads while the application runs its tests.
    Storage::fake('tmp-for-tests');
    app(Kernel::class)->prependMiddleware(NestUploadedFiles::class);
});

/**
 * Drops these SVG files on the upload field, as an editor dragging them from their desktop does: one `drop` holding every file.
 *
 * ponytail: text files only, V1 at the latest: pest-plugin-browser's server garbles a binary multipart body (a JPEG reaches
 * Livewire as `application/octet-stream`); the `Kami` suite uploads photos and PDFs. Binary fixtures once the plugin keeps bytes.
 *
 * @param  array<string, string>  $files  Content by filename.
 */
function dropFiles(array $files): string
{
    $list = implode(',', array_map(fn (string $name, string $content): string => sprintf('new File([%s], %s, { type: "image/svg+xml" })', json_encode($content), json_encode($name)), array_keys($files), $files));

    return <<<JS
        const transfer = new DataTransfer();
        [{$list}].forEach((file) => transfer.items.add(file));
        const target = document.querySelector('.filepond--drop-label');
        // FilePond takes a drop only within its area: the pointer at its centre.
        const area = target.getBoundingClientRect();
        const at = { clientX: area.x + area.width / 2, clientY: area.y + area.height / 2 };
        for (const type of ['dragenter', 'dragover', 'drop']) {
            target.dispatchEvent(new DragEvent(type, { bubbles: true, cancelable: true, dataTransfer: transfer, ...at }));
        }
        JS;
}

it('takes several files dragged onto the library at once', function () {
    $page = signIn()
        ->navigate('/admin/media')
        ->click('internal:role=button[name="Upload"s]')
        ->assertSee('Up to 20 files')
        // FilePond loads after the modal (`x-load`): its drop area replaces the plain input.
        ->assertPresent('.filepond--drop-label')
        ->assertNoAccessibilityIssues();

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>';
    $page->script(dropFiles(['Logo.svg' => $svg, 'Icon.svg' => $svg]));

    $page->assertSee('Icon.svg')
        ->press('internal:role=dialog >> internal:role=button[name="Upload"s]')
        ->assertSee('2 files uploaded.')
        ->assertSee('logo')
        ->assertSee('Missing')
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors();

    expect(Media::query()->count())->toBe(2);

    visit('/admin/media')->inDarkMode()->assertSee('logo')->assertNoAccessibilityIssues()->assertNoJavaScriptErrors();
    visit('/admin/media')->on()->mobile()->assertSee('logo')->assertNoAccessibilityIssues()->assertNoJavaScriptErrors();
});
