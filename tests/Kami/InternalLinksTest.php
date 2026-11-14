<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View as ViewFactory;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Psyao\Kami\Admin\Internal\EntryResource\EditEntry;
use Psyao\Kami\Admin\Internal\EntryResource\ListEntries;
use Psyao\Kami\Content\Actions\DeleteEntry;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Actions\TrashEntry;
use Psyao\Kami\Content\Blocks\Block;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Fields\RichText;
use Psyao\Kami\Media\Actions\DeleteMedia;
use Psyao\Kami\Media\Actions\SaveMedia;
use Psyao\Kami\Media\Media;
use Workbench\App\Content\Article;
use Workbench\App\Content\Footer;
use Workbench\App\Content\Page;

uses(RefreshDatabase::class);

/** A Block holding a document of its own. */
final class LinkNote extends Block
{
    public function handle(): string
    {
        return 'link_note';
    }

    public function fields(): array
    {
        return [RichText::make('note')];
    }

    public function view(): string
    {
        return 'blocks-fixture::note';
    }
}

/** On Article's handle and table, its body offering LinkNote. */
final class LinkingProse extends ContentType
{
    #[Override]
    protected ?string $prefix = 'articles';

    public function handle(): string
    {
        return 'article';
    }

    public function fields(): array
    {
        return [RichText::make('body')->blocks([LinkNote::class])];
    }
}

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

it('renders a link to a Draft, a trashed target, one under a hidden ancestor or another Site\'s as its text', function () {
    $draft = linkedPage('Brouillon', ['status' => 'draft']);
    $trashed = linkedPage('Corbeille');
    $hidden = linkedPage('Parent cache', ['status' => 'draft']);
    $child = linkedPage('Enfant', ['parent' => $hidden->id]);
    $source = linkingArticle(linking(draft: "kami://entry/{$draft->id}", trashed: "kami://entry/{$trashed->id}", child: "kami://entry/{$child->id}", plain: 'https://example.com/'));
    resolve(TrashEntry::class)->handle($trashed);

    expect(Entry::query()->findOrFail($source->id)->body->toHtml())
        ->toBe('<p>drafttrashedchild<a href="https://example.com/">plain</a></p>');
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

function linkModal(Entry $entry, string | array $role = 'administrator'): Testable
{
    return entriesPage(EditEntry::class, $role, ['record' => $entry->id]);
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

it('treats another Site\'s Entry or Media item as gone: rendered as text, dropped on save, never indexed', function () {
    $page = linkedPage('Ailleurs');
    $pdf = linkedDocument();
    $paragraph = linking(page: "kami://entry/{$page->id}", pdf: "kami://media/{$pdf->id}");
    $source = linkingArticle($paragraph);
    DB::table('kami_references')->where('source_entry_id', $source->id)->delete();
    DB::table('kami_entries')->where('id', $page->id)->update(['site' => 'blog']);
    DB::table('kami_media')->where('id', $pdf->id)->update(['site' => 'blog']);

    expect(Entry::query()->findOrFail($source->id)->body->toHtml())->toBe('<p>pagepdf</p>');

    linkingArticle($paragraph, entry: $source);

    expect(DB::table('kami_references')->where('source_entry_id', $source->id)->count())->toBe(0)
        ->and(Entry::query()->findOrFail($source->id)->body->content()['content'][0]['content'])->toEqual([['type' => 'text', 'text' => 'page'], ['type' => 'text', 'text' => 'pdf']]);
});

it('lists an Entry linking another among those using it, in the Trash\'s warning', function () {
    $page = linkedPage('Cible');
    linkingArticle(linking(page: "kami://entry/{$page->id}"), ['title' => 'Liant']);

    entriesPage(ListEntries::class, type: 'page')
        ->mountAction(TestAction::make('delete')->table($page))
        ->assertMountedActionModalSee('Liant');
});

it('leaves out of the Entry picker the types the User may not view and those with no pages', function () {
    linkedPage('Brouillon visible', ['status' => 'draft']);
    resolve(SaveEntry::class)->handle(Footer::class, ['title' => 'Pied visible'], locale: 'fr');
    $source = linkingArticle(linking(text: 'https://example.com/'));
    $search = fn (Testable $modal): array => array_values($modal->mountAction(TestAction::make('link')->schemaComponent('body'))->instance()->getSchemaComponent('mountedActionSchema0.entry')->getSearchResults('visible'));

    expect($search(linkModal($source)))->toBe(['Brouillon visible'])
        ->and($search(linkModal($source, ['kami.entries.article.view', 'kami.entries.article.update', 'kami.entries.article.publish'])))->toBe([]);
});

it('empties the other inputs once one is filled, so the link gets what the editor sees', function () {
    $page = linkedPage('Cible');

    linkModal(linkingArticle(linking(text: 'https://example.com/')))
        ->mountAction(TestAction::make('link')->schemaComponent('body'), ['url' => 'https://example.com/'])
        ->fillForm(['entry' => $page->id], 'mountedActionSchema0')
        ->assertSchemaStateSet(['entry' => $page->id, 'media' => null, 'url' => null], 'mountedActionSchema0')
        ->fillForm(['url' => 'https://example.org/'], 'mountedActionSchema0')
        ->assertSchemaStateSet(['entry' => null, 'media' => null, 'url' => 'https://example.org/'], 'mountedActionSchema0');
});

it('uploads a Media item from the link modal\'s picker and picks it', function () {
    Storage::fake('local');
    Storage::fake('public');

    linkModal(linkingArticle(linking(text: 'https://example.com/')))
        ->mountAction(TestAction::make('link')->schemaComponent('body'))
        ->callAction(TestAction::make('createOption')->schemaComponent('media', 'mountedActionSchema0'), ['file' => UploadedFile::fake()->createWithContent('brochure.pdf', (string) file_get_contents(fixture('media/brochure.pdf')))])
        ->assertHasNoFormComponentActionErrors()
        ->assertSchemaStateSet(['media' => Media::query()->sole()->id], 'mountedActionSchema0');
});

it('indexes, cleans and links in its Entry\'s Locale a Block\'s own RichText', function () {
    config(['kami.types' => [LinkingProse::class, Page::class], 'kami.blocks' => [LinkNote::class]]);
    ViewFactory::addNamespace('blocks-fixture', dirname(__DIR__) . '/Fixtures/blocks');
    $target = linkedPage('Cible');
    linkedPage('Target', locale: 'en', entry: $target);
    $deleted = linkedPage('Supprimee');
    $note = ['type' => 'doc', 'content' => [linking(target: "kami://entry/{$target->id}", deleted: "kami://entry/{$deleted->id}")]];
    $body = ['type' => 'doc', 'content' => [['type' => 'block', 'attrs' => ['handle' => 'link_note', 'data' => ['note' => $note]]]]];
    $save = fn (string $locale, ?Entry $entry = null): Entry => resolve(SaveEntry::class)->handle(LinkingProse::class, ['title' => 'Source', 'status' => 'published', 'body' => $body], $entry, $locale);
    $source = $save('fr');

    expect(DB::table('kami_references')->where('source_entry_id', $source->id)->pluck('target_entry_id')->all())->toEqualCanonicalizing([$target->id, $deleted->id]);

    resolve(TrashEntry::class)->handle($deleted);
    resolve(DeleteEntry::class)->handle(Entry::query()->withUnpublished()->withTrashed()->findOrFail($deleted->id));
    $save('en', $source);

    expect(DB::table('kami_references')->where(['source_entry_id' => $source->id, 'locale' => 'en'])->pluck('target_entry_id')->all())->toBe([$target->id])
        ->and(trim(Entry::query()->readIn('en')->findOrFail($source->id)->body->toHtml()))->toBe('<div class="note"><p><a href="http://localhost/en/target">target</a>deleted</p></div>');
});
