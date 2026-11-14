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
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Psyao\Kami\Admin\Internal\EntryResource\EditEntry;
use Psyao\Kami\Content\Actions\DeleteEntry;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Actions\TrashEntry;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Media\Actions\DeleteMedia;
use Psyao\Kami\Media\Actions\SaveMedia;
use Psyao\Kami\Media\Media;
use Workbench\App\Content\Article;
use Workbench\App\Content\Page;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

/** A paragraph linking each text to its href. */
function linking(string ...$links): array
{
    return ['type' => 'paragraph', 'content' => array_map(
        fn (string $text, string $href): array => ['type' => 'text', 'text' => $text, 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]],
        array_keys($links),
        $links,
    )];
}

/** @param  array<string, mixed>  $values */
function linkingArticle(array $paragraph, array $values = [], string $locale = 'fr', ?Entry $entry = null): Entry
{
    return resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Source', 'author' => 'Ada', 'status' => 'published', 'body' => ['type' => 'doc', 'content' => [$paragraph]], ...$values], $entry, $locale);
}

/** @param  array<string, mixed>  $values */
function linkedPage(string $title, array $values = [], string $locale = 'fr', ?Entry $entry = null): Entry
{
    return resolve(SaveEntry::class)->handle(Page::class, ['title' => $title, 'status' => 'published', ...$values], $entry, $locale);
}

function linkedDocument(): Media
{
    Storage::fake('local');

    return resolve(SaveMedia::class)->handle(fixture('media/brochure.pdf'));
}

it('renders a kami:// link at its target\'s current URL, ancestors included, and a Media item at its own', function () {
    $parent = linkedPage('Parent');
    $child = linkedPage('Enfant', ['parent' => $parent->id]);
    $pdf = linkedDocument();
    $source = linkingArticle(linking(page: "kami://entry/{$child->id}", pdf: "kami://media/{$pdf->id}"));

    linkedPage('Parent', ['slug' => 'renomme'], entry: $parent);

    expect(Entry::query()->findOrFail($source->id)->body->toHtml())
        ->toBe('<p><a href="http://localhost/renomme/enfant">page</a><a href="' . e($pdf->url()) . '">pdf</a></p>');
});

it('renders a link to a Draft, a trashed or a missing target as its text', function () {
    $draft = linkedPage('Brouillon', ['status' => 'draft']);
    $source = linkingArticle(linking(draft: "kami://entry/{$draft->id}", plain: 'https://example.com/'));

    expect(Entry::query()->findOrFail($source->id)->body->toHtml())
        ->toBe('<p>draft<a href="https://example.com/">plain</a></p>');
});

it('indexes a document\'s links as its References, which keep a linked Media item from being deleted', function () {
    $page = linkedPage('Cible');
    $pdf = linkedDocument();
    $source = linkingArticle(linking(page: "kami://entry/{$page->id}", pdf: "kami://media/{$pdf->id}", again: "kami://entry/{$page->id}"), ['title' => 'Liant']);

    expect(DB::table('kami_references')->where('source_entry_id', $source->id)->orderBy('position')->get(['field', 'locale', 'target_entry_id', 'target_media_id'])->map(fn (object $row): array => (array) $row)->all())
        ->toBe([
            ['field' => 'body', 'locale' => 'fr', 'target_entry_id' => $page->id, 'target_media_id' => null],
            ['field' => 'body', 'locale' => 'fr', 'target_entry_id' => null, 'target_media_id' => $pdf->id],
        ])
        ->and(fn () => resolve(DeleteMedia::class)->handle($pdf))->toThrow(ValidationException::class, 'Liant');
});

it('drops on save a link whose target is gone, keeping its text, and keeps one to a trashed Entry', function () {
    [$trashed, $deleted] = [linkedPage('Corbeille'), linkedPage('Supprimee')];
    $paragraph = linking(trashed: "kami://entry/{$trashed->id}", deleted: "kami://entry/{$deleted->id}", missing: 'kami://media/01JABC', odd: 'kami://entry/a/b');
    $source = linkingArticle($paragraph);
    resolve(TrashEntry::class)->handle($trashed);
    resolve(TrashEntry::class)->handle($deleted);
    resolve(DeleteEntry::class)->handle(Entry::query()->withUnpublished()->withTrashed()->findOrFail($deleted->id));

    linkingArticle($paragraph, entry: $source);

    $stored = json_decode((string) DB::table('kami_type_article')->where(['entry_id' => $source->id, 'locale' => 'fr'])->value('body'), true);
    expect($stored['content'][0]['content'])->toEqual([
        ['type' => 'text', 'text' => 'trashed', 'marks' => [['type' => 'link', 'attrs' => ['href' => "kami://entry/{$trashed->id}"]]]],
        ['type' => 'text', 'text' => 'deleted'],
        ['type' => 'text', 'text' => 'missing'],
        ['type' => 'text', 'text' => 'odd'],
    ])
        ->and(DB::table('kami_references')->where('source_entry_id', $source->id)->pluck('target_entry_id')->all())->toBe([$trashed->id]);
});

it('reads every target of a document in one batch, within an exact query count', function () {
    Model::shouldBeStrict();
    $parent = linkedPage('Parent');
    $child = linkedPage('Enfant', ['parent' => $parent->id]);
    $other = linkedPage('Autre');
    $pdf = linkedDocument();
    $source = linkingArticle(linking(a: "kami://entry/{$child->id}", b: "kami://entry/{$other->id}", c: "kami://media/{$pdf->id}", d: "kami://entry/{$child->id}"));
    $body = Entry::query()->findOrFail($source->id)->body;

    DB::enableQueryLog();
    $html = $body->toHtml();

    expect(substr_count($html, '<a href='))->toBe(4)
        // The Entries with their Translations and type rows, the nested ones' Paths and their ancestors', the Media items and their Translations.
        ->and(DB::getQueryLog())->toHaveCount(7);
});

it('links in the Locale its Entry is read in, even when its text fell back to the default one', function () {
    $target = linkedPage('Cible');
    linkedPage('Target', locale: 'en', entry: $target);
    $source = linkingArticle(linking(target: "kami://entry/{$target->id}"));
    linkingArticle(['type' => 'paragraph'], ['title' => 'Source', 'status' => 'published'], 'en', $source);

    $read = Entry::query()->readIn('en')->findOrFail($source->id);

    expect($read->body->toHtml())->toBe('<div lang="fr"><p><a href="http://localhost/en/target">target</a></p></div>');
});

function linkModal(Entry $entry): Testable
{
    // Filament opens its panel to a User that is not a `FilamentUser` in the local environment only.
    config(['app.env' => 'local']);
    test()->actingAs(tap(User::forceCreate(['name' => 'Ada Lovelace', 'email' => uniqid() . '@example.test', 'password' => 'secret']), fn (User $user) => DB::table('kami_role_user')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->getKey(), 'role' => 'administrator'])));
    Filament::setCurrentPanel('admin');
    Filament::setCurrentResourceConfigurationKey('article');

    return Livewire::test(EditEntry::class, ['record' => $entry->id]);
}

it('links the selection to an Entry or a Media item picked in the link modal, and stores the kami:// href', function (string $kind) {
    $draft = linkedPage('Brouillon', ['status' => 'draft']);
    $pdf = linkedDocument();
    [$data, $href] = $kind === 'entry' ? [['entry' => $draft->id], "kami://entry/{$draft->id}"] : [['media' => $pdf->id], "kami://media/{$pdf->id}"];
    $source = linkingArticle(linking(text: 'https://example.com/'), ['status' => 'draft']);

    linkModal($source)
        ->callAction(TestAction::make('link')->schemaComponent('body'), $data, ['editorSelection' => ['head' => 1, 'anchor' => 3]])
        ->assertHasNoFormComponentActionErrors()
        ->assertDispatched('run-rich-editor-commands', fn (string $event, array $parameters): bool => $parameters['commands'][0]['name'] === 'setLink' && $parameters['commands'][0]['arguments'][0]['href'] === $href)
        ->set('data.body', ['type' => 'doc', 'content' => [linking(text: $href)]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(json_decode((string) DB::table('kami_type_article')->where(['entry_id' => $source->id, 'locale' => 'fr'])->value('body'), true)['content'][0]['content'][0]['marks'][0]['attrs']['href'])->toBe($href);
})->with(['entry', 'media']);

it('reopens a kami:// link on its pick, and an address as typed', function (string $href, array $expected) {
    $page = linkedPage('Cible');
    $href = str_replace('{id}', $page->id, $href);

    linkModal(linkingArticle(linking(text: $href)))
        ->mountAction(TestAction::make('link')->schemaComponent('body'), ['url' => $href])
        ->assertSchemaStateSet(array_map(fn (?string $value): ?string => $value === '{id}' ? $page->id : $value, $expected), 'mountedActionSchema0');
})->with([
    'an Entry' => ['kami://entry/{id}', ['entry' => '{id}', 'media' => null, 'url' => null]],
    'an address' => ['https://example.com/', ['entry' => null, 'media' => null, 'url' => 'https://example.com/']],
]);

it('offers in the Entry picker the Drafts of every type with pages the User may view', function () {
    linkedPage('Brouillon visible', ['status' => 'draft']);

    $modal = linkModal(linkingArticle(linking(text: 'https://example.com/')))
        ->mountAction(TestAction::make('link')->schemaComponent('body'));

    expect(array_values($modal->instance()->getSchemaComponent('mountedActionSchema0.entry')->getSearchResults('visible')))->toBe(['Brouillon visible']);
});
