<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Section;
use Filament\Schemas\JsContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Psyao\Kami\Admin\Internal\EntryResource\CreateEntry;
use Psyao\Kami\Admin\Internal\EntryResource\EditEntry;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Fields\RichText;
use Psyao\Kami\Content\RichText\Document;
use Psyao\Kami\Content\SiteSettings;
use Psyao\Kami\Content\Taxonomy;
use Psyao\Kami\Facades\Kami;
use Psyao\Kami\Media\Actions\SaveMedia;
use Psyao\Kami\Media\Media;
use Workbench\App\Content\Article;
use Workbench\App\Content\Home;
use Workbench\App\Content\Label;
use Workbench\App\Content\Page;
use Workbench\App\Content\Project;
use Workbench\App\Content\Tag;

uses(RefreshDatabase::class);

/** A Taxonomy with a URL, as the Workbench's Tag has none, under its handle: the panel registers the Workbench's types. */
final class SeoTopic extends Taxonomy
{
    public function handle(): string
    {
        return 'testimonial';
    }
}

beforeEach(function () {
    config(['app.name' => 'Kami Site', 'kami.types' => [Article::class, Home::class, Label::class, Page::class, Project::class, SeoTopic::class, SiteSettings::class, Tag::class]]);
    Kami::routes();
    Storage::fake('local');
    Storage::fake('public');
});

/** The type's edit or create page in the admin, for a User holding the Role. */
function seoPage(string $type, ?Entry $entry = null, string $locale = 'fr', string | array $role = 'administrator'): Testable
{
    return entriesPage($entry instanceof Entry ? EditEntry::class : CreateEntry::class, $role, match (true) {
        ! $entry instanceof Entry => [],
        $locale === 'fr' => ['record' => $entry->id],
        default => ['record' => $entry->id, 'locale' => $locale],
    }, $type);
}

function seoPhoto(string $fixture = 'rotated-gps.jpg'): Media
{
    return resolve(SaveMedia::class)->handle(fixture("media/{$fixture}"));
}

it('places a collapsed SEO section on every type with a URL, Terms and Singletons included, last', function (string $type) {
    $components = seoPage($type)->instance()->getSchema('form')->getComponents();
    $section = end($components);

    expect($section)->toBeInstanceOf(Section::class)
        ->and($section->getHeading())->toBe('SEO')
        ->and($section->isCollapsed())->toBeTrue();
})->with(['article', 'testimonial', 'label', 'project']);

it('leaves the SEO section out of a type with no URL', function () {
    seoPage('tag')->assertFormFieldDoesNotExist('meta_title');
});

it('leaves the SEO section out of a Global Set', function () {
    config(['kami.types' => [SiteSettings::class]]);
    resolve(SaveEntry::class)->handle(SiteSettings::class, [], locale: 'fr');
    signInWith();
    Filament::setCurrentPanel('admin');
    Filament::setCurrentResourceConfigurationKey('site_settings');

    Livewire::test(EditEntry::class)
        ->assertFormFieldExists('default_description')
        ->assertFormFieldExists('default_social_image')
        ->assertFormFieldDoesNotExist('meta_title')
        ->assertFormFieldDoesNotExist('seo_image');
});

it('saves the Meta title, Meta description and noindex per Locale and the Social image for all, and fills them back', function () {
    $photo = seoPhoto();
    $entry = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada']);

    seoPage('article', $entry)
        ->fillForm(['meta_title' => 'Un titre', 'meta_description' => 'Une phrase.', 'noindex' => true, 'seo_image' => $photo->id])
        ->call('save')
        ->assertHasNoFormErrors();

    seoPage('article', $entry)->assertSchemaStateSet(['meta_title' => 'Un titre', 'meta_description' => 'Une phrase.', 'noindex' => true, 'seo_image' => $photo->id]);
    seoPage('article', $entry, 'en')->assertSchemaStateSet(['meta_title' => null, 'meta_description' => null, 'noindex' => false, 'seo_image' => $photo->id]);
});

/** Whether the form's input shows that placeholder. */
function placeholds(Testable $page, string $input, ?string $placeholder): Testable
{
    return $page->assertFormFieldExists($input, fn (Field $field): bool => $field->getPlaceholder() === $placeholder);
}

it('shows what the head derives as placeholders: the title with the Site name, the summary, the type\'s image', function () {
    $cover = seoPhoto();
    $project = resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Kami', 'cover' => $cover->id], locale: 'fr');
    $article = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada', 'body' => Document::fromHtml('<p>Le début.</p>', RichText::make('body'))]);

    $page = seoPage('article', $article);
    placeholds($page, 'meta_title', 'Bonjour – Kami Site');
    placeholds($page, 'meta_description', 'Le début.');
    placeholds($page, 'seo_image', null);

    placeholds(seoPage('project', $project), 'seo_image', "Left blank: {$cover->name}");
});

it('follows the title typed in the form', function () {
    placeholds(seoPage('article')->set('data.title', 'Salut'), 'meta_title', 'Salut – Kami Site');
});

it('shows the Site name alone, the Site\'s default description and image on the home page, in its own Locale', function () {
    $default = seoPhoto();
    $logo = seoPhoto('logo.svg');
    resolve(SaveEntry::class)->handle(SiteSettings::class, ['site_name' => 'Mon site', 'default_description' => 'Le site.', 'default_social_image' => $logo->id], locale: 'fr');
    $home = resolve(SaveEntry::class)->handle(Home::class, ['title' => 'Accueil'], locale: 'fr');
    $article = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada']);

    $page = seoPage('home', $home);
    placeholds($page, 'meta_title', 'Mon site');
    placeholds($page, 'meta_description', 'Le site.');
    // An SVG is skipped, as on the page.
    placeholds($page, 'seo_image', null);
    placeholds(seoPage('article', $article), 'meta_description', null);
    placeholds(seoPage('home', $home, 'en'), 'meta_description', null);

    resolve(SaveEntry::class)->handle(SiteSettings::class, ['default_social_image' => $default->id], SiteSettings::entry(), 'fr');

    placeholds(seoPage('article', $article), 'seo_image', "Left blank: {$default->name}");
});

it('counts the characters of the Meta title and description in the browser, never blocking a longer one', function () {
    $entry = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada']);

    seoPage('article', $entry)
        ->assertFormFieldExists('meta_title', fn (Field $field): bool => $field->getHint() instanceof JsContent && str_contains($field->getHint()->toHtml(), 'about 60 shown'))
        ->assertFormFieldExists('meta_description', fn (Field $field): bool => $field->getHint() instanceof JsContent && str_contains($field->getHint()->toHtml(), 'about 160 shown'))
        ->fillForm(['meta_title' => str_repeat('a', 120), 'meta_description' => str_repeat('b', 400)])
        ->call('save')
        ->assertHasNoFormErrors();
});

it('marks the Social image as shared, on a translatable type only', function () {
    $entry = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada']);

    seoPage('article', $entry)->assertFormFieldExists('seo_image', fn (Field $field): bool => $field->getHint() === 'Shared by every language: changing it here changes it everywhere.');
    seoPage('label')->assertFormFieldExists('seo_image', fn (Field $field): bool => $field->getHint() === null);
});

it('keeps the Social image from a User without publish while another Translation is visible (ADR 0019)', function () {
    $photo = seoPhoto();
    $entry = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada', 'status' => 'published']);

    seoPage('article', $entry, 'en', ['kami.entries.article.view', 'kami.entries.article.update'])
        ->assertFormFieldDisabled('seo_image')
        ->fillForm(['title' => 'Hello', 'seo_image' => $photo->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Entry::query()->withUnpublished()->findOrFail($entry->id)->seo_image)->toBeNull();
});

it('offers no SVG to either Social image picker, and refuses one uploaded there', function () {
    $photo = seoPhoto();
    $logo = seoPhoto('logo.svg');
    resolve(SaveEntry::class)->handle(SiteSettings::class, [], locale: 'fr');
    $entry = resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Kami'], locale: 'fr');
    $project = seoPage('project', $entry);
    $form = $project->instance()->getSchema('form');

    expect(array_keys($form->getComponent('seo_image')->getSearchResults('')))->toBe([$photo->id])
        ->and(array_keys($form->getComponent('cover')->getSearchResults('')))->toEqualCanonicalizing([$photo->id, $logo->id]);

    $project->callAction(TestAction::make('createOption')->schemaComponent('seo_image', 'form'), ['file' => UploadedFile::fake()->createWithContent('logo.svg', (string) file_get_contents(fixture('media/logo.svg')))])
        ->assertHasErrors();

    expect(Media::query()->count())->toBe(2);

    Filament::setCurrentResourceConfigurationKey('site_settings');
    $settings = Livewire::test(EditEntry::class)->instance()->getSchema('form');

    expect(array_keys($settings->getComponent('default_social_image')->getSearchResults('')))->toBe([$photo->id]);
});

it('names the Social image on its upload button', function () {
    seoPage('article')->assertActionHasLabel(TestAction::make('createOption')->schemaComponent('seo_image', 'form'), 'Upload a file for Social image');
});

it('previews the unsaved SEO values in the head', function () {
    $photo = seoPhoto();
    $entry = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada']);

    $page = seoPage('article', $entry)
        ->fillForm(['meta_title' => 'Pas encore enregistré', 'seo_image' => $photo->id])
        ->call('preview')
        ->assertHasNoFormErrors();

    $this->get(url('/admin/kami/preview/' . $page->get('previewToken')))->assertOk()
        ->assertSee('<title>Pas encore enregistré</title>', escape: false)
        ->assertSee('<meta property="og:image" content="' . $photo->url('kami-social') . '">', escape: false);

    expect(Entry::query()->withUnpublished()->sole()->seo_image)->toBeNull();
});
