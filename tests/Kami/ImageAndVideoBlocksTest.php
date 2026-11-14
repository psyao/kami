<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Psyao\Kami\Admin\Internal\EntryResource\EditEntry;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Facades\Kami;
use Psyao\Kami\Media\Actions\SaveMedia;
use Workbench\App\Content\Article;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

/** @param  array<mixed>  $data */
function kamiBlock(string $handle, array $data): array
{
    return ['type' => 'block', 'attrs' => ['handle' => $handle, 'data' => $data]];
}

/** @param  array<mixed>  ...$blocks */
function articleWith(array ...$blocks): Entry
{
    return resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada', 'status' => 'published', 'body' => ['type' => 'doc', 'content' => $blocks]], locale: 'fr');
}

/**
 * @param  array<mixed>  ...$blocks
 * @return array<string, list<string>>
 */
function blockErrors(array ...$blocks): array
{
    try {
        articleWith(...$blocks);
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

it('renders an Image Block as its picture in a figure, and Video Blocks as provider links with one facade script, within an exact query count', function () {
    Model::shouldBeStrict();
    Kami::routes();
    $photo = resolve(SaveMedia::class)->handle(fixture('media/pixel.png'), ['alt' => 'Un pixel']);
    articleWith(
        kamiBlock('image', ['image' => [$photo->id], 'caption' => 'Un <b>pixel</b>']),
        kamiBlock('video', ['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=42', 'caption' => 'Le clip']),
        kamiBlock('video', ['url' => 'https://vimeo.com/76979871']),
    );

    DB::enableQueryLog();
    $html = (string) $this->get('/articles/bonjour')->assertOk()->getContent();

    expect($html)
        ->toMatch('~<figure class="kami-figure">\s*<picture class="kami-image">.*kami-block-640\.webp.*alt="Un pixel".*</picture>\s*<figcaption>Un &lt;b&gt;pixel&lt;/b&gt;</figcaption>\s*</figure>~s')
        ->toContain('<div class="kami-video-facade" data-kami-video="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1" data-title="Le clip">')
        ->toContain('<a href="https://www.youtube.com/watch?v=dQw4w9WgXcQ">Regarder « Le clip » sur YouTube</a>')
        ->toContain('<figcaption>Le clip</figcaption>')
        ->toContain('<div class="kami-video-facade" data-kami-video="https://player.vimeo.com/video/76979871?dnt=1&amp;autoplay=1" data-title="Vidéo Vimeo">')
        ->toContain('<a href="https://vimeo.com/76979871">Regarder la vidéo sur Vimeo</a>')
        ->not->toContain('<iframe')
        ->and(substr_count($html, '<script type="module" src="http://localhost/vendor/kami/video.js"></script>'))->toBe(1)
        ->and(substr_count($html, '<script'))->toBe(1)
        // The page's six, then the Image's Media item and its Translations.
        ->and(DB::getQueryLog())->toHaveCount(8);
});

it('reads each YouTube and Vimeo address form into its privacy-preserving player', function (string $url, string $player) {
    Kami::routes();
    articleWith(kamiBlock('video', ['url' => $url]));

    expect($this->get('/articles/bonjour')->getContent())->toContain('data-kami-video="' . e($player) . '"');
})->with([
    'watch' => ['https://www.youtube.com/watch?feature=share&v=dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1'],
    'mobile' => ['https://m.youtube.com/watch?v=dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1'],
    'short link' => ['https://youtu.be/dQw4w9WgXcQ?si=x', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1'],
    'Shorts' => ['https://youtube.com/shorts/dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1'],
    'Vimeo' => ['https://www.vimeo.com/76979871/', 'https://player.vimeo.com/video/76979871?dnt=1&autoplay=1'],
]);

it('refuses a Video address that is not YouTube\'s or Vimeo\'s, with no network call', function (string $url) {
    expect(blockErrors(kamiBlock('video', ['url' => $url])))->toBe(['body.blocks.0.url' => ['The address in Video 1 is not a YouTube or Vimeo address.']]);
})->with([
    'another provider' => ['https://www.dailymotion.com/video/x7tgad0'],
    'a YouTube channel' => ['https://www.youtube.com/@kami'],
    'a lookalike host' => ['https://youtube.com.example.test/watch?v=dQw4w9WgXcQ'],
]);

it('gives something that is no address the url rule\'s message alone', function () {
    expect(blockErrors(kamiBlock('video', ['url' => 'youtube dQw4w9WgXcQ'])))->toBe(['body.blocks.0.url' => ['The address in Video 1 field must be a valid URL.']]);
});

it('requires an Image Block\'s image, and an image', function () {
    $brochure = resolve(SaveMedia::class)->handle(fixture('media/brochure.pdf'));

    expect(blockErrors(kamiBlock('image', ['caption' => 'Seule']), kamiBlock('image', ['image' => [$brochure->id]]), kamiBlock('video', [])))
        ->toHaveKeys(['body.blocks.0.image', 'body.blocks.1.image', 'body.blocks.2.url']);
});

it('previews an Image Block in the editor with its thumbnail and alt text, its caption escaped', function () {
    $photo = resolve(SaveMedia::class)->handle(fixture('media/pixel.png'), ['alt' => 'Un "pixel"']);
    $entry = articleWith();
    config(['app.env' => 'local']);
    $this->actingAs(tap(User::forceCreate(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'secret']), fn (User $user) => DB::table('kami_role_user')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->getKey(), 'role' => 'administrator'])));
    Filament::setCurrentPanel('admin');
    Filament::setCurrentResourceConfigurationKey('article');

    Livewire::test(EditEntry::class, ['record' => $entry->id])
        ->callAction(TestAction::make('customBlock')->schemaComponent('body'), ['image' => $photo->id, 'caption' => '<b>Vu</b>'], ['id' => 'image', 'mode' => 'insert', 'editorSelection' => ['type' => 'text', 'anchor' => 0, 'head' => 0]])
        ->assertHasNoFormComponentActionErrors()
        ->assertDispatched('run-rich-editor-commands', fn (string $event, array $parameters): bool => base64_decode($parameters['commands'][0]['arguments'][0]['attrs']['preview'])
            === '<img src="' . e($photo->url('admin-thumb')) . '" alt="Un &quot;pixel&quot;"><p>&lt;b&gt;Vu&lt;/b&gt;</p>');
});
