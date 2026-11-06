<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Psyao\Kami\Facades\Kami;
use Psyao\Kami\Media\Media;
use Workbench\App\Models\User;
use Workbench\Database\Seeders\DatabaseSeeder;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Kami::routes();
    $this->seed(DatabaseSeeder::class);
});

it('seeds an Entry in each state, answering only where it is visible', function () {
    $this->get('/articles/bonjour')->assertOk();
    $this->get('/en/articles/hello')->assertOk();
    $this->get('/articles/seulement-en-francais')->assertOk();
    $this->get('/en/articles/french-only-so-far')->assertNotFound();
    $this->get('/articles/a-paraitre')->assertNotFound();
    $this->get('/articles/brouillon')->assertNotFound();
    $this->get('/articles/dans-la-corbeille')->assertNotFound();
    $this->get('/articles/ancien-article')->assertRedirect('http://localhost/articles/bonjour');
    $this->get('/en/articles/blank-summary')->assertOk()->assertSee('lang="fr"', escape: false);
    $this->get('/label/urgent')->assertOk();
    $this->get('/en/label/urgent')->assertOk();
    $this->get('/a-propos')->assertOk();
    $this->get('/en/about')->assertOk();
    $this->get('/project/refonte')->assertOk();
    $this->get('/')->assertOk()->assertSee('Bienvenue');
    $this->get('/en')->assertOk()->assertSee('Welcome');
    $this->get('/testimonial/une-cliente')->assertNotFound();
});

it('seeds an administrator and an editor who may update but not publish', function () {
    $user = fn (string $email): User => User::query()->where('email', $email)->sole();
    $editor = $user('editor@example.test');

    expect(Hash::check('password', $user('admin@example.test')->password))->toBeTrue()
        ->and(Hash::check('password', $editor->password))->toBeTrue()
        ->and(Gate::forUser($user('admin@example.test'))->allows('kami.entries.article.publish'))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('kami.entries.article.update'))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('kami.entries.article.publish'))->toBeFalse();
});

it('seeds a Media library holding a photo described in both Locales, an image without alt text, a decorative one and a document', function () {
    expect(Media::query()->orderBy('name')->get()->map(fn (Media $media): array => [$media->name, $media->family, $media->alt(), $media->isDecorative()])->all())->toBe([
        ['brochure', 'document', null, false],
        ['holiday-photo', 'image', 'Une photo de vacances', false],
        ['pixel', 'image', null, false],
        ['rotated-gps', 'image', null, true],
    ])
        ->and(Media::query()->where('name', 'holiday-photo')->sole()->translations->pluck('alt', 'locale')->sortKeys()->all())->toBe(['en' => 'A holiday photo', 'fr' => 'Une photo de vacances']);
});
