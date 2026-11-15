<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View as ViewFactory;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Psyao\Kami\Admin\Internal\EntryResource\EditEntry;
use Psyao\Kami\Admin\Internal\MediaResource\EditMedia;
use Psyao\Kami\Content\Actions\DeleteEntry;
use Psyao\Kami\Content\Actions\DuplicateEntry;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Actions\TrashEntry;
use Psyao\Kami\Content\Blocks\Block;
use Psyao\Kami\Content\Blocks\Image;
use Psyao\Kami\Content\Blocks\Video;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Fields\Date;
use Psyao\Kami\Content\Fields\Entries;
use Psyao\Kami\Content\Fields\Field;
use Psyao\Kami\Content\Fields\Media as MediaField;
use Psyao\Kami\Content\Fields\RichText;
use Psyao\Kami\Content\Fields\Terms;
use Psyao\Kami\Content\Fields\Text;
use Psyao\Kami\Content\Internal\ContentModelDrift;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Content\Taxonomy;
use Psyao\Kami\Content\Term;
use Psyao\Kami\Facades\Kami;
use Psyao\Kami\Media\Actions\DeleteMedia;
use Psyao\Kami\Media\Actions\SaveMedia;
use Psyao\Kami\Media\Media;
use Psyao\Kami\Routing\Internal\RenderEntry;
use Symfony\Component\HttpFoundation\Response;
use Workbench\App\Content\Article;
use Workbench\App\Content\Blocks\Callout;
use Workbench\App\Content\Page;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

beforeEach(fn () => ViewFactory::addNamespace('blocks-fixture', dirname(__DIR__) . '/Fixtures/blocks'));

/** A Block whose Fields each test declares. */
final class Declaring extends Block
{
    /** @var list<Field> */
    public static array $declared = [];

    public function handle(): string
    {
        return 'declaring';
    }

    public function fields(): array
    {
        return self::$declared;
    }

    public function view(): string
    {
        return 'blocks-fixture::declaring';
    }
}

/** A type offering Blocks as each test declares, on Article's handle and table for its `body` column. */
final class BlockProse extends ContentType
{
    #[Override]
    protected ?string $prefix = 'articles';

    /** @var list<Field> */
    public static array $declared = [];

    public function handle(): string
    {
        return 'article';
    }

    public function fields(): array
    {
        return self::$declared;
    }
}

/** A Taxonomy whose Term Archive hands back what it lists. */
final class BlockTopic extends Taxonomy
{
    #[Override]
    public function render(Entry $entry, ?CursorPaginator $entries = null): View | Response
    {
        return view()->make('kami.blocks.callout', ['entries' => $entries]);
    }
}

it('refuses a Block declaration at first read', function (array $blocks, array $fields, string $message) {
    Declaring::$declared = $fields;
    BlockProse::$declared = [RichText::make('body')->blocks([Declaring::class])];
    config(['kami.types' => [BlockProse::class], 'kami.blocks' => $blocks]);

    expect(fn () => resolve(ContentTypes::class)->all())->toThrow(InvalidArgumentException::class, $message);
})->with([
    'not listed' => [[], [Text::make('text')], 'offers Declaring, which is not listed in kami.blocks'],
    'not a Block' => [[Article::class], [], 'Every entry of kami.blocks is a class extending'],
    'a handle taken' => [[Declaring::class, Declaring::class], [], 'is already the handle of Declaring'],
    'Blocks inside a Block' => [[Declaring::class, Callout::class], [RichText::make('note')->blocks([Callout::class])], 'cannot offer Blocks inside a Block'],
    'a condition' => [[Declaring::class], [Text::make('a'), Text::make('b')->visibleWhenFilled('a')], 'cannot take a condition inside a Block'],
    'a default' => [[Declaring::class], [Text::make('a')->default('x')], 'cannot take a default inside a Block'],
    'an index' => [[Declaring::class], [Text::make('a')->filterable()], 'cannot be filterable or sortable'],
    'a Field twice' => [[Declaring::class], [Text::make('a'), Text::make('a')], 'appears twice'],
    'a handle not snake_case' => [[Declaring::class], [Text::make('Bad')], 'is not snake_case'],
]);

it('takes a RichText with no Blocks inside a Block', function () {
    Declaring::$declared = [RichText::make('note')];
    BlockProse::$declared = [RichText::make('body')->blocks([Declaring::class])];
    config(['kami.types' => [BlockProse::class], 'kami.blocks' => [Declaring::class]]);

    expect(resolve(ContentTypes::class)->all())->toHaveCount(2);
});

/** @param  array<mixed>  $data */
function callout(array $data, string $handle = 'callout'): array
{
    return ['type' => 'block', 'attrs' => ['handle' => $handle, 'data' => $data]];
}

/** @param  array<mixed>  ...$content */
function bodyOf(array ...$content): array
{
    return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Before']]], ...$content]];
}

/** @param  array<string, mixed>  $values */
function saveCalloutArticle(array $values, string $status = 'published', ?Entry $entry = null): Entry
{
    return resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada', 'status' => $status, ...$values], $entry, 'fr');
}

/** As stored: MySQL's json column reorders an object's keys, so a test compares with `toEqual()`.
 *
 * @return array<mixed>|null
 */
function storedBody(Entry $entry, string $locale = 'fr'): ?array
{
    $stored = DB::table('kami_type_article')->where(['entry_id' => $entry->id, 'locale' => $locale])->value('body');

    return $stored === null ? null : json_decode((string) $stored, true);
}

/** @param  array<string, mixed>  $values */
function calloutErrors(array $values, string $status = 'published'): array
{
    try {
        saveCalloutArticle($values, $status);
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

function calloutImage(): Media
{
    Storage::fake('local');

    return resolve(SaveMedia::class)->handle(fixture('media/pixel.png'));
}

it('stores an offered Block with its declared Fields only, each normalised', function () {
    $image = calloutImage();
    $entry = saveCalloutArticle(['body' => bodyOf(callout(['text' => 'Note', 'image' => $image->id, 'forged' => '<script>']))]);

    expect(storedBody($entry)['content'][1])->toEqual(callout(['text' => 'Note', 'tone' => null, 'image' => [$image->id]]));
});

it('keeps an unavailable Block as it is, unvalidated', function (array $block) {
    Declaring::$declared = [Text::make('text')->required()];
    config(['kami.blocks' => [Callout::class, Image::class, Video::class, Declaring::class]]);
    $entry = saveCalloutArticle(['body' => bodyOf($block)]);

    expect(storedBody($entry)['content'][1])->toEqual($block);
})->with([
    'no class declares it' => [callout(['items' => ['01J']], 'gallery')],
    'declared but not offered' => [callout(['forged' => 1], 'declaring')],
]);

it('requires a Block\'s required Field at publication only, naming the Block', function () {
    $body = bodyOf(callout(['text' => 'First']), callout(['tone' => 'info']));

    expect(saveCalloutArticle(['body' => $body], 'draft'))->toBeInstanceOf(Entry::class)
        ->and(calloutErrors(['body' => $body]))->toBe(['body.blocks.1.text' => ['The text in Callout 2 field is required.']]);
});

it('checks a Block\'s other rules on every save', function () {
    expect(calloutErrors(['body' => bodyOf(callout(['text' => 'A', 'tone' => 'loud', 'image' => ['01JNOTAMEDIA00000000000000']]))], 'draft'))
        ->toHaveKeys(['body.blocks.0.tone', 'body.blocks.0.image']);
});

it('validates the stored Blocks when publishing a Draft', function () {
    $entry = saveCalloutArticle(['body' => bodyOf(callout(['tone' => 'info']))], 'draft');

    expect(fn () => saveCalloutArticle([], 'published', $entry))->toThrow(ValidationException::class, 'The text in Callout 1 field is required.');
});

it('indexes a Block\'s Media and Entries as References of its document, in its Locale', function () {
    Declaring::$declared = [MediaField::make('image'), Entries::make('related')->of(Page::class)];
    BlockProse::$declared = [RichText::make('body')->blocks([Declaring::class])];
    config(['kami.types' => [BlockProse::class, Page::class], 'kami.blocks' => [Declaring::class]]);
    $image = calloutImage();
    $target = resolve(SaveEntry::class)->handle(Page::class, ['title' => 'Target']);
    $save = fn (array $body, ?Entry $entry = null): Entry => resolve(SaveEntry::class)->handle(BlockProse::class, ['title' => 'Source', 'body' => $body], $entry, 'fr');
    $entry = $save(bodyOf(callout(['image' => [$image->id], 'related' => [$target->id]], 'declaring'), callout(['image' => [$image->id]], 'declaring')));

    expect(DB::table('kami_references')->where('source_entry_id', $entry->id)->orderBy('position')->get(['field', 'locale', 'target_entry_id', 'target_media_id'])->map(fn (object $row): array => (array) $row)->all())->toBe([
        ['field' => 'body', 'locale' => 'fr', 'target_entry_id' => $target->id, 'target_media_id' => null],
        ['field' => 'body', 'locale' => 'fr', 'target_entry_id' => null, 'target_media_id' => $image->id],
    ]);

    $save(bodyOf(), $entry);

    expect(DB::table('kami_references')->where('source_entry_id', $entry->id)->count())->toBe(0);
});

it('keeps a Draft from blanking a required Block Field of a Shared document another Translation publishes', function () {
    Declaring::$declared = [Text::make('text')->required()];
    BlockProse::$declared = [RichText::make('body')->shared()->blocks([Declaring::class])];
    config(['kami.types' => [BlockProse::class], 'kami.blocks' => [Declaring::class]]);
    $entry = resolve(SaveEntry::class)->handle(BlockProse::class, ['title' => 'Publié', 'status' => 'published', 'body' => bodyOf(callout(['text' => 'Dit'], 'declaring'))], locale: 'fr');

    expect(fn () => resolve(SaveEntry::class)->handle(BlockProse::class, ['title' => 'Draft', 'body' => bodyOf(callout([], 'declaring'))], $entry, 'en'))
        ->toThrow(ValidationException::class, 'The text in Declaring 1 field is required.');
});

it('keeps saving an Entry whose Block links an Entry trashed or deleted since: a deleted one leaves the document, a trashed one stays', function () {
    Declaring::$declared = [Entries::make('related')->of(Page::class)->required()];
    BlockProse::$declared = [RichText::make('body')->blocks([Declaring::class])];
    config(['kami.types' => [BlockProse::class, Page::class], 'kami.blocks' => [Declaring::class]]);
    [$kept, $trashed, $deleted] = array_map(fn (string $title): Entry => resolve(SaveEntry::class)->handle(Page::class, ['title' => $title]), ['Kept', 'Trashed', 'Deleted']);
    $body = bodyOf(callout(['related' => [$kept->id, $trashed->id, $deleted->id]], 'declaring'));
    $save = fn (array $values, Entry $entry): Entry => resolve(SaveEntry::class)->handle(BlockProse::class, $values, $entry, 'fr');
    $entry = resolve(SaveEntry::class)->handle(BlockProse::class, ['title' => 'Source', 'status' => 'published', 'body' => $body], locale: 'fr');
    resolve(TrashEntry::class)->handle($trashed);
    resolve(TrashEntry::class)->handle($deleted);
    resolve(DeleteEntry::class)->handle(Entry::query()->withUnpublished()->withTrashed()->findOrFail($deleted->id));

    $save(['title' => 'Renamed'], $entry);
    $save(['body' => $body], $entry);

    expect(storedBody($entry)['content'][1]['attrs']['data']['related'])->toBe([$kept->id, $trashed->id])
        ->and(DB::table('kami_references')->where('source_entry_id', $entry->id)->orderBy('position')->pluck('target_entry_id')->all())->toBe([$kept->id, $trashed->id]);
});

it('lists an Entry showing a Media item in a Block among the Entries using it', function () {
    $image = calloutImage();
    $entry = saveCalloutArticle(['title' => 'Illustrated', 'body' => bodyOf(callout(['text' => 'A', 'image' => [$image->id]]))]);
    signInToBlocks();

    Livewire::test(EditMedia::class, ['record' => $image->id])->assertSeeHtml('href="' . url("admin/articles/{$entry->id}/edit") . '"');
});

it('keeps a Media item a Block shows from being deleted, naming the Entry', function () {
    $image = calloutImage();
    saveCalloutArticle(['title' => 'Illustrated', 'body' => bodyOf(callout(['text' => 'A', 'image' => [$image->id]]))]);

    expect(fn () => resolve(DeleteMedia::class)->handle($image))->toThrow(ValidationException::class, 'Illustrated');
});

it('lists in a Term\'s Entries none that only a Block links it from', function () {
    Declaring::$declared = [Terms::make('topics')->of(BlockTopic::class)];
    BlockProse::$declared = [RichText::make('body')->blocks([Declaring::class])];
    config(['kami.types' => [BlockProse::class, BlockTopic::class], 'kami.blocks' => [Declaring::class]]);
    $tag = resolve(SaveEntry::class)->handle(BlockTopic::class, ['title' => 'Laravel']);
    $entry = resolve(SaveEntry::class)->handle(BlockProse::class, ['title' => 'Linking', 'status' => 'published', 'body' => bodyOf(callout(['topics' => [$tag->id]], 'declaring'))], locale: 'fr');
    $term = Term::query()->findOrFail($tag->id);

    expect(DB::table('kami_references')->where(['source_entry_id' => $entry->id, 'target_entry_id' => $tag->id])->exists())->toBeTrue()
        ->and($term->entries()->count())->toBe(0)
        ->and(RenderEntry::call(new BlockTopic, $term)->getData()['entries']->items())->toBe([]);
});

it('gives a duplicate its documents\' References, which the doctor reads as the RichText\'s own', function () {
    $image = calloutImage();
    $source = saveCalloutArticle(['body' => bodyOf(callout(['text' => 'A', 'image' => [$image->id]]))]);

    $copy = resolve(DuplicateEntry::class)->handle($source);

    expect(DB::table('kami_references')->where('source_entry_id', $copy->id)->get(['field', 'locale', 'target_media_id'])->map(fn (object $row): array => (array) $row)->all())
        ->toBe([['field' => 'body', 'locale' => 'fr', 'target_media_id' => $image->id]])
        ->and(resolve(ContentModelDrift::class)->strandedValues())->toBe([]);
});

it('renders a Block through its view in a page, an unavailable one as nothing, within an exact query count', function () {
    Model::shouldBeStrict();
    Kami::routes();
    $image = calloutImage();
    saveCalloutArticle(['body' => bodyOf(callout(['text' => 'Mind <b>this</b>', 'tone' => 'warning', 'image' => [$image->id]]), callout(['items' => ['01J']], 'gallery'), callout(['text' => 'Plain']))]);

    DB::enableQueryLog();
    $response = $this->get('/articles/bonjour')->assertOk();

    expect($response->getContent())
        ->toContain('<p>Before</p>')
        ->toContain('<aside class="callout callout-warning">')
        ->toContain('<picture class="kami-image">')
        ->toContain('<p>Mind &lt;b&gt;this&lt;/b&gt;</p>')
        ->toContain('<aside class="callout callout-info">')
        ->not->toContain('gallery')
        ->and(substr_count((string) $response->getContent(), '<aside'))->toBe(2)
        // The page's six (Path, Entry, Translations, type rows, Site settings, Social image Reference), the layout's primary Menu, then the Blocks' Media items and their Translations.
        ->and(DB::getQueryLog())->toHaveCount(9);
});

it('gives a Block\'s view its data typed as an Entry reads it, Entries visible in the page\'s Locale only', function () {
    Declaring::$declared = [Entries::make('related')->of(Page::class), RichText::make('note'), Date::make('day'), MediaField::make('gallery')->multiple()];
    BlockProse::$declared = [RichText::make('body')->blocks([Declaring::class])];
    config(['kami.types' => [BlockProse::class, Page::class], 'kami.blocks' => [Declaring::class]]);
    $first = resolve(SaveEntry::class)->handle(Page::class, ['title' => 'First', 'status' => 'published']);
    $draft = resolve(SaveEntry::class)->handle(Page::class, ['title' => 'Hidden']);
    $second = resolve(SaveEntry::class)->handle(Page::class, ['title' => 'Second', 'status' => 'published']);
    $data = ['related' => [$second->id, $draft->id, $first->id], 'note' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Said']]]]], 'day' => '2026-11-14'];
    $entry = resolve(SaveEntry::class)->handle(BlockProse::class, ['title' => 'Source', 'status' => 'published', 'body' => bodyOf(callout($data, 'declaring'))], locale: 'fr');
    $body = Entry::query()->findOrFail($entry->id)->body;

    DB::enableQueryLog();
    $html = $body->toHtml();

    expect($html)->toContain('<p class="related">Second, First</p>')
        ->toContain('<div class="note"><p>Said</p></div>')
        ->toContain('<p class="day">2026-11-14</p>')
        ->toContain('<p class="gallery">0</p>')
        // The Entries with their Translations and type rows; no Media id, no Media query.
        ->and(DB::getQueryLog())->toHaveCount(3);
});

/** @param  array<string, mixed>  $parameters */
function blockEditor(array $parameters): Testable
{
    signInToBlocks();
    Filament::setCurrentResourceConfigurationKey('article');

    return Livewire::test(EditEntry::class, $parameters);
}

function signInToBlocks(): void
{
    // Filament opens its panel to a User that is not a `FilamentUser` in the local environment only.
    config(['app.env' => 'local']);
    test()->actingAs(tap(User::forceCreate(['name' => 'Ada Lovelace', 'email' => uniqid() . '@example.test', 'password' => 'secret']), fn (User $user) => DB::table('kami_role_user')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->getKey(), 'role' => 'administrator'])));
    Filament::setCurrentPanel('admin');
}

/** @param  array<mixed>  $config */
function inEditor(array $config, string $id = 'callout'): array
{
    return ['type' => 'doc', 'content' => [['type' => 'customBlock', 'attrs' => ['id' => $id, 'config' => $config]]]];
}

it('inserts a Block from its modal, built from its Fields, labelled and summarised, its summary escaped', function () {
    $entry = saveCalloutArticle(['body' => bodyOf()], 'draft');

    blockEditor(['record' => $entry->id])
        ->callAction(TestAction::make('customBlock')->schemaComponent('body'), ['text' => '<b>Mind</b>', 'tone' => 'warning'], ['id' => 'callout', 'mode' => 'insert', 'editorSelection' => ['type' => 'text', 'anchor' => 7, 'head' => 7]])
        ->assertHasNoFormComponentActionErrors()
        ->assertDispatched('run-rich-editor-commands', function (string $event, array $parameters): bool {
            $attrs = $parameters['commands'][0]['arguments'][0]['attrs'];

            return $attrs['id'] === 'callout'
                && $attrs['config']['text'] === '<b>Mind</b>' && $attrs['config']['tone'] === 'warning'
                && $attrs['label'] === 'Callout'
                && base64_decode($attrs['preview']) === '<p>&lt;b&gt;Mind&lt;/b&gt;</p>';
        });
});

it('saves a Block the editor holds, and reopens it with its data to edit', function () {
    $image = calloutImage();
    $entry = saveCalloutArticle(['body' => bodyOf()], 'draft');

    blockEditor(['record' => $entry->id])
        ->set('data.body', inEditor(['text' => 'Mind', 'tone' => 'info', 'image' => [$image->id], 'forged' => 'x']))
        ->call('save')
        ->assertHasNoFormErrors();

    expect(storedBody($entry))->toEqual(['type' => 'doc', 'content' => [callout(['text' => 'Mind', 'tone' => 'info', 'image' => [$image->id]])]]);

    blockEditor(['record' => $entry->id])
        // A single Media Field's picker holds one id.
        ->assertSet('data.body.content.0.attrs', ['id' => 'callout', 'config' => ['text' => 'Mind', 'tone' => 'info', 'image' => $image->id]])
        ->callAction(TestAction::make('customBlock')->schemaComponent('body'), ['text' => 'Changed'], ['id' => 'callout', 'mode' => 'edit', 'config' => ['text' => 'Mind', 'tone' => 'info'], 'editorSelection' => ['type' => 'node', 'anchor' => 0]])
        ->assertHasNoFormComponentActionErrors()
        ->assertDispatched('run-rich-editor-commands', fn (string $event, array $parameters): bool => $parameters['commands'][0]['arguments'][0]['attrs']['config']['text'] === 'Changed');
});

it('labels a Block\'s modal inputs from kami-blocks.php, in the page\'s Locale', function () {
    $entry = saveCalloutArticle(['body' => bodyOf()], 'draft');

    blockEditor(['record' => $entry->id, 'locale' => 'en'])
        ->mountAction(TestAction::make('customBlock')->schemaComponent('body'), ['id' => 'callout', 'mode' => 'insert'])
        ->assertSchemaComponentExists('text', 'mountedActionSchema0', fn (TextInput $input): bool => $input->getLabel() === 'Text' && $input->getExtraInputAttributes()['lang'] === 'en')
        ->assertSchemaComponentExists('tone', 'mountedActionSchema0', fn (Select $select): bool => $select->getOptions() === ['info' => 'Information', 'warning' => 'Warning']);
});

it('shows a Block\'s errors on its editor when publishing', function () {
    $entry = saveCalloutArticle(['body' => bodyOf()], 'draft');

    blockEditor(['record' => $entry->id])
        ->set('data.body', inEditor(['tone' => 'info']))
        ->callAction(TestAction::make('publish')->schemaComponent('form-actions', schema: 'content'))
        ->assertHasFormErrors(['body' => 'The text in Callout 1 field is required.']);
});

it('keeps an unavailable Block read-only, deletable, under its handle', function () {
    $entry = saveCalloutArticle(['body' => bodyOf(callout(['items' => ['01J']], 'gallery'))], 'draft');

    $page = blockEditor(['record' => $entry->id]);
    $attrs = $page->get('data.body')['content'][1]['attrs'];

    expect($attrs['config'] ?? null)->toBeNull()
        ->and($attrs['label'])->toBe('Block unavailable: gallery');

    $page->call('save')->assertHasNoFormErrors();

    expect(storedBody($entry)['content'][1])->toEqual(callout(['items' => ['01J']], 'gallery'));
});

it('refuses a 65th Block on the panel, naming the ceiling', function () {
    // The Workbench's three Blocks, which its Article offers, then 62 more.
    foreach (range(1, 62) as $n) {
        app()->bind("numbered-block-{$n}", fn (): Block => new class($n) extends Block
        {
            public function __construct(private readonly int $n) {}

            public function handle(): string
            {
                return "block_{$this->n}";
            }

            public function fields(): array
            {
                return [];
            }

            public function view(): string
            {
                return 'kami.blocks.callout';
            }
        });
    }

    config(['kami.blocks' => [Callout::class, Image::class, Video::class, ...array_map(fn (int $n): string => "numbered-block-{$n}", range(1, 62))]]);

    expect(fn () => blockEditor(['record' => saveCalloutArticle([], 'draft')->id]))->toThrow(Exception::class, 'kami.blocks lists 65 Blocks; the admin\'s editor reaches 64 at most');
});
