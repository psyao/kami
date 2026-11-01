<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Filament\Schemas\Schema as FilamentSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Psyao\Kami\Admin\Internal\EntryResource\EditEntry;
use Psyao\Kami\Admin\Internal\RichTextInput;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Fields\Group;
use Psyao\Kami\Content\Fields\Repeater;
use Psyao\Kami\Content\Fields\RichText;
use Psyao\Kami\Content\Fields\Text;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Content\RichText\Document;
use Workbench\App\Content\Article;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

/** A type whose Fields each test sets, over a `body` column. */
final class Prose extends ContentType
{
    /** @var array<mixed> */
    public static array $declared = [];

    public function fields(): array
    {
        return self::$declared; // @phpstan-ignore return.type
    }
}

/** @param  array<mixed>  ...$content */
function doc(array ...$content): array
{
    return ['type' => 'doc', 'content' => array_values($content)];
}

/** @param  array<mixed>  ...$marks */
function txt(string $text, array ...$marks): array
{
    return ['type' => 'text', 'text' => $text, ...($marks === [] ? [] : ['marks' => array_values($marks)])];
}

function para(string $text): array
{
    return ['type' => 'paragraph', 'content' => [txt($text)]];
}

function linkedPara(string $href, string $text = 'link'): array
{
    return ['type' => 'paragraph', 'content' => [txt($text, ['type' => 'link', 'attrs' => ['href' => $href]])]];
}

function aBlock(): array
{
    return ['type' => 'block', 'attrs' => ['handle' => 'gallery', 'data' => ['items' => ['01J']]]];
}

function saveProse(mixed $body, RichText $field): ?array
{
    Prose::$declared = [$field];
    config(['kami.types' => [Prose::class]]);

    $entry = resolve(SaveEntry::class)->handle(Prose::class, ['title' => uniqid('Kami'), 'body' => $body], locale: 'fr');
    $stored = DB::table('kami_type_prose')->where(['entry_id' => $entry->id, 'locale' => 'fr'])->value('body');

    return $stored === null ? null : json_decode((string) $stored, true);
}

function savedArticle(array $values = [], string $locale = 'fr', ?Entry $entry = null): Entry
{
    return resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada', 'status' => 'published', ...$values], $entry, $locale);
}

beforeEach(function () {
    Schema::create('kami_type_prose', function (Blueprint $table): void {
        $table->foreignUlid('entry_id')->constrained('kami_entries')->cascadeOnDelete();
        $table->string('locale', 20);
        $table->json('body')->nullable();
        $table->unique(['entry_id', 'locale']);
    });
});

/** @param  array<string, mixed>  $parameters */
function editorPage(array $parameters): Testable
{
    // Filament opens its panel to a User that is not a `FilamentUser` in the local environment only.
    config(['app.env' => 'local']);
    test()->actingAs(tap(User::forceCreate(['name' => 'Ada Lovelace', 'email' => uniqid() . '@example.test', 'password' => 'secret']), fn (User $user) => DB::table('kami_role_user')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->getKey(), 'role' => 'administrator'])));
    Filament::setCurrentPanel('admin');
    Filament::setCurrentResourceConfigurationKey('article');

    return Livewire::test(EditEntry::class, $parameters);
}

it('normalises a document against the allowed set', function (array $given, ?array $stored) {
    expect(saveProse($given, RichText::make('body')))->toBe($stored);
})->with([
    'an allowed document, unchanged' => [
        doc(
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [txt('Title')]],
            ['type' => 'paragraph', 'content' => [txt('a', ['type' => 'bold'], ['type' => 'italic']), ['type' => 'hardBreak'], txt('b')]],
            ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [para('one')]]]],
            ['type' => 'orderedList', 'content' => [['type' => 'listItem', 'content' => [para('two')]]]],
            ['type' => 'blockquote', 'content' => [para('said')]],
            ['type' => 'horizontalRule'],
        ),
        doc(
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [txt('Title')]],
            ['type' => 'paragraph', 'content' => [txt('a', ['type' => 'bold'], ['type' => 'italic']), ['type' => 'hardBreak'], txt('b')]],
            ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [para('one')]]]],
            ['type' => 'orderedList', 'content' => [['type' => 'listItem', 'content' => [para('two')]]]],
            ['type' => 'blockquote', 'content' => [para('said')]],
            ['type' => 'horizontalRule'],
        ),
    ],
    'an unknown node unwrapped, its text kept as paragraphs' => [
        doc(['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'content' => [para('cell')]], ['type' => 'details', 'content' => [txt('bare')]]]]]]),
        doc(para('cell'), para('bare')),
    ],
    'an unknown inline node keeps its text' => [
        doc(['type' => 'paragraph', 'content' => [txt('a '), ['type' => 'mention', 'content' => [txt('b')]]]]),
        doc(para('a b')),
    ],
    'an unknown mark removed, its neighbours joined' => [
        doc(['type' => 'paragraph', 'content' => [txt('a', ['type' => 'underline']), txt('b'), txt('c', ['type' => 'strike', 'attrs' => ['style' => 'color:red']])]]),
        doc(para('abc')),
    ],
    'attributes allow-listed' => [
        doc(
            ['type' => 'paragraph', 'attrs' => ['textAlign' => 'center', 'style' => 'color:red'], 'content' => [txt('a')]],
            ['type' => 'heading', 'attrs' => ['level' => 3, 'id' => 'x', 'onclick' => 'alert(1)'], 'content' => [txt('b')]],
            ['type' => 'paragraph', 'content' => [txt('c', ['type' => 'link', 'attrs' => ['href' => 'https://a.test', 'target' => '_blank', 'rel' => 'x', 'class' => 'y']])]],
            ['type' => 'orderedList', 'attrs' => ['start' => 5], 'content' => [['type' => 'listItem', 'attrs' => ['x' => 1], 'content' => [para('d')]]]],
        ),
        doc(
            para('a'),
            ['type' => 'heading', 'attrs' => ['level' => 3], 'content' => [txt('b')]],
            linkedPara('https://a.test', 'c'),
            ['type' => 'orderedList', 'content' => [['type' => 'listItem', 'content' => [para('d')]]]],
        ),
    ],
    'a heading outside the set takes the nearest level' => [
        doc(['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [txt('a')]], ['type' => 'heading', 'attrs' => ['level' => 6], 'content' => [txt('b')]], ['type' => 'heading', 'content' => [txt('c')]]),
        doc(['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [txt('a')]], ['type' => 'heading', 'attrs' => ['level' => 4], 'content' => [txt('b')]], ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [txt('c')]]),
    ],
    'bare text wrapped in list items and quotes' => [
        doc(['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [txt('one')]], para('stray')]], ['type' => 'blockquote', 'content' => [txt('said')]]),
        doc(['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [para('one')]], ['type' => 'listItem', 'content' => [para('stray')]]]], ['type' => 'blockquote', 'content' => [para('said')]]),
    ],
    'bare text at the root wrapped' => [
        doc(txt('a'), ['type' => 'hardBreak'], txt('b'), para('c')),
        doc(['type' => 'paragraph', 'content' => [txt('a'), ['type' => 'hardBreak'], txt('b')]], para('c')),
    ],
    'a list item out of its list unwrapped' => [doc(['type' => 'listItem', 'content' => [para('a')]]), doc(para('a'))],
    'an empty list goes' => [doc(para('a'), ['type' => 'bulletList', 'content' => []]), doc(para('a'))],
    'a code block without ->code() keeps its text' => [
        doc(['type' => 'codeBlock', 'attrs' => ['language' => 'php'], 'content' => [txt('echo 1;')]]),
        doc(para('echo 1;')),
    ],
    'inline code without ->code() removed' => [
        doc(['type' => 'paragraph', 'content' => [txt('a', ['type' => 'code'])]]),
        doc(para('a')),
    ],
    'garbage among the nodes skipped' => [
        ['type' => 'doc', 'content' => ['x', 1, null, ['no' => 'type'], para('a'), ['type' => 'text', 'text' => ''], ['type' => 'text', 'text' => ['x']]]],
        doc(para('a')),
    ],
    'a Block kept untouched' => [doc(para('a'), aBlock()), doc(para('a'), aBlock())],
    'a Block with a bad shape goes' => [doc(para('a'), ['type' => 'block', 'attrs' => ['handle' => ['x']]]), doc(para('a'))],
    'a Block alone is content' => [doc(aBlock()), doc(aBlock())],
    'an empty document is null' => [doc(), null],
    'empty paragraphs are null' => [doc(['type' => 'paragraph'], ['type' => 'paragraph', 'content' => []]), null],
    'blank text is null' => [doc(para("  \n ")), null],
    'a rule alone is null' => [doc(['type' => 'horizontalRule']), null],
    'an empty heading among text stays' => [doc(['type' => 'heading', 'attrs' => ['level' => 2]], para('a')), doc(['type' => 'heading', 'attrs' => ['level' => 2]], para('a'))],
    'something else than a document is null' => [['foo' => 'bar'], null],
]);

it('keeps a safe link and reduces any other to its text', function (string $href, bool $kept) {
    expect(saveProse(doc(linkedPara($href)), RichText::make('body')))->toBe(doc($kept ? linkedPara($href) : para('link')));
})->with([
    ['https://a.test/x', true],
    ['http://a.test', true],
    ['mailto:a@a.test', true],
    ['tel:+41000000', true],
    ['/relative/path', true],
    ['relative/path:with-colon', true],
    ['page.html', true],
    ['#anchor', true],
    ['?q=a:b', true],
    ['kami://entry/01JABC', true],
    ['kami://media/01JABC', true],
    ['javascript:alert(1)', false],
    ['JavaScript:alert(1)', false],
    [" java\tscript:alert(1)", false],
    ["\x01javascript:alert(1)", false],
    ['data:text/html,x', false],
    ['vbscript:x', false],
    ['ftp://a.test', false],
    ['kami:entry', false],
    ['', false],
]);

it('reduces a link whose href is no string to its text', function () {
    $given = doc(['type' => 'paragraph', 'content' => [txt('a', ['type' => 'link', 'attrs' => ['href' => ['x']]]), txt('b', ['type' => 'link'])]]);

    expect(saveProse($given, RichText::make('body')))->toBe(doc(para('ab')));
});

it('allows code with ->code()', function () {
    $document = doc(
        ['type' => 'paragraph', 'content' => [txt('a', ['type' => 'code'])]],
        ['type' => 'codeBlock', 'attrs' => ['language' => 'php'], 'content' => [txt("echo 1;\n")]],
        ['type' => 'codeBlock', 'content' => [txt('plain')]],
    );

    expect(saveProse($document, RichText::make('body')->code()))->toBe($document);
});

it('cleans a code block', function () {
    $given = doc(['type' => 'codeBlock', 'attrs' => ['language' => '"><script>'], 'content' => [txt('a', ['type' => 'bold']), ['type' => 'hardBreak'], txt('b')]]);

    expect(saveProse($given, RichText::make('body')->code()))->toBe(doc(['type' => 'codeBlock', 'content' => [txt('ab')]]));
});

it('narrows the heading levels with ->headings()', function () {
    $given = doc(['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [txt('a')]], ['type' => 'heading', 'attrs' => ['level' => 4], 'content' => [txt('b')]]);

    expect(saveProse($given, RichText::make('body')->headings([3, 4])))->toBe(doc(['type' => 'heading', 'attrs' => ['level' => 3], 'content' => [txt('a')]], ['type' => 'heading', 'attrs' => ['level' => 4], 'content' => [txt('b')]]));
});

it('stores a normalised document unchanged when normalised again', function () {
    $forged = doc(['type' => 'table', 'content' => [para('a')]], ['type' => 'blockquote', 'content' => [txt('b', ['type' => 'underline']), txt('c')]]);
    $once = saveProse($forged, RichText::make('body'));

    expect(saveProse($once, RichText::make('body')))->toBe($once);
});

it('refuses a value that is no document', function () {
    expect(fn () => saveProse('<p>html</p>', RichText::make('body')))->toThrow(ValidationException::class);
});

it('reads a blank required document as blank when publishing', function () {
    Prose::$declared = [RichText::make('body')->required()];
    config(['kami.types' => [Prose::class]]);

    expect(fn () => resolve(SaveEntry::class)->handle(Prose::class, ['title' => 'Kami', 'status' => 'published', 'body' => doc(['type' => 'paragraph'])], locale: 'fr'))
        ->toThrow(ValidationException::class);
});

it('keeps a stored document when a save leaves it out, and publishes with it', function () {
    Prose::$declared = [RichText::make('body')->required()];
    config(['kami.types' => [Prose::class]]);
    $save = resolve(SaveEntry::class);

    $entry = $save->handle(Prose::class, ['title' => 'Kami', 'body' => doc(para('a'))], locale: 'fr');
    $save->handle(Prose::class, ['status' => 'published'], $entry, 'fr');

    expect($entry->fresh()->body->text())->toBe('a');
});

it('reads a Document even for null', function () {
    $body = savedArticle()->body;

    expect($body)->toBeInstanceOf(Document::class)
        ->and($body->isEmpty())->toBeTrue()
        ->and($body->toHtml())->toBe('')
        ->and($body->text())->toBe('')
        ->and($body->excerpt())->toBe('')
        ->and($body->blocks())->toBe([]);
});

it('renders the allowed set with Kami\'s extensions', function () {
    $body = savedArticle(['body' => doc(
        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [txt('Title')]],
        ['type' => 'paragraph', 'content' => [txt('a', ['type' => 'bold']), txt('b', ['type' => 'italic']), ['type' => 'hardBreak'], txt('<script>', ['type' => 'code'])]],
        linkedPara('https://a.test/?a=1&b="2"'),
        ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [para('one')]]]],
        ['type' => 'orderedList', 'content' => [['type' => 'listItem', 'content' => [para('two')]]]],
        ['type' => 'blockquote', 'content' => [para('said')]],
        ['type' => 'horizontalRule'],
        ['type' => 'codeBlock', 'attrs' => ['language' => 'php'], 'content' => [txt('<?php')]],
    )])->body;

    expect($body->toHtml())->toBe(
        '<h2>Title</h2>'
        . '<p><strong>a</strong><em>b</em><br><code>&lt;script&gt;</code></p>'
        . '<p><a href="https://a.test/?a=1&amp;b=&quot;2&quot;">link</a></p>'
        . '<ul><li><p>one</p></li></ul>'
        . '<ol><li><p>two</p></li></ol>'
        . '<blockquote><p>said</p></blockquote>'
        . '<hr>'
        . '<pre><code class="language-php">&lt;?php</code></pre>'
    );
});

it('outputs its HTML through Blade\'s echo', function () {
    $entry = savedArticle(['body' => doc(para('a'))]);

    expect(Blade::render('{{ $body }}', ['body' => $entry->body]))->toBe('<p>a</p>');
});

it('renders a kami:// link as text and a Block as nothing until M10', function () {
    $body = savedArticle(['body' => doc(linkedPara('kami://entry/01JABC', 'target'), aBlock())])->body;

    expect($body->toHtml())->toBe('<p>target</p>');
});

it('reads text, an excerpt and its Blocks', function () {
    $body = savedArticle(['body' => doc(
        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [txt('Un')]],
        ['type' => 'paragraph', 'content' => [txt('de', ['type' => 'bold']), txt('ux'), ['type' => 'hardBreak'], txt('trois')]],
        aBlock(),
        ['type' => 'block', 'attrs' => ['handle' => 'video', 'data' => []]],
        para('quatre cinq six'),
    )])->body;

    expect($body->text())->toBe('Un deux trois quatre cinq six')
        ->and($body->isEmpty())->toBeFalse()
        ->and($body->excerpt(13))->toBe('Un deux trois…')
        ->and($body->excerpt(15))->toBe('Un deux trois…')
        ->and($body->excerpt())->toBe('Un deux trois quatre cinq six')
        ->and($body->blocks())->toBe([['handle' => 'gallery', 'data' => ['items' => ['01J']]], ['handle' => 'video', 'data' => []]])
        ->and($body->blocks('video'))->toBe([['handle' => 'video', 'data' => []]]);
});

it('carries lang on a wrapper when it falls back', function () {
    $entry = savedArticle(['body' => doc(para('Bonjour'))]);
    savedArticle(['title' => 'Hello'], 'en', $entry);

    $read = Entry::query()->findOrFail($entry->id);
    $read->readIn('en');

    expect($read->fallbackLocale('body'))->toBe('fr')
        ->and($read->body->toHtml())->toBe('<div lang="fr"><p>Bonjour</p></div>')
        ->and($read->body->text())->toBe('Bonjour');
});

it('falls back when a Translation\'s document is blank', function () {
    $entry = savedArticle(['body' => doc(para('Bonjour'))]);
    savedArticle(['title' => 'Hello', 'body' => doc(['type' => 'paragraph'])], 'en', $entry);

    $read = Entry::query()->findOrFail($entry->id);
    $read->readIn('en');

    expect($read->fallbackLocale('body'))->toBe('fr');
});

it('refuses a declaration at first read', function (array $fields, string $message) {
    Prose::$declared = $fields;
    config(['kami.types' => [Prose::class]]);

    expect(fn () => resolve(ContentTypes::class)->all())->toThrow(InvalidArgumentException::class, $message);
})->with([
    'in a Repeater' => [[Repeater::make('rows', [RichText::make('body')])], 'cannot hold the RichText "body"'],
    'in a Group' => [[Group::make('address', [RichText::make('body')])], 'cannot hold the RichText "body"'],
    'an H1' => [[RichText::make('body')->headings([1, 2])], 'levels 2, 3 and 4'],
    'an H5' => [[RichText::make('body')->headings([5])], 'levels 2, 3 and 4'],
    'no level' => [[RichText::make('body')->headings([])], 'levels 2, 3 and 4'],
    'as a condition\'s target' => [[RichText::make('body')->shared(), Text::make('note')->visibleWhenFilled('body')], 'whose value a condition cannot read'],
]);

it('is Translatable by default in a json column', function () {
    $field = RichText::make('body');

    expect($field->column())->toBe('json')->and($field->isTranslatable())->toBeTrue();
});

it('reloads a saved document unchanged after a save through the form', function (array $document) {
    $entry = savedArticle(['body' => $document]);

    editorPage(['record' => $entry->id])->call('save')->assertHasNoFormErrors();

    expect(json_decode((string) DB::table('kami_type_article')->where(['entry_id' => $entry->id, 'locale' => 'fr'])->value('body'), true))->toBe($document);
})->with([
    'prose' => [doc(
        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [txt('Title')]],
        ['type' => 'paragraph', 'content' => [txt('a', ['type' => 'bold'], ['type' => 'italic']), ['type' => 'hardBreak'], txt('b', ['type' => 'code'])]],
        ['type' => 'paragraph'],
        ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [para('one')]]]],
        ['type' => 'blockquote', 'content' => [para('said')]],
        ['type' => 'horizontalRule'],
        ['type' => 'codeBlock', 'attrs' => ['language' => 'php'], 'content' => [txt('<?php')]],
    )],
    'a kami:// link' => [doc(linkedPara('kami://entry/01JABC'))],
    'a Block' => [doc(para('a'), aBlock(), para('b'))],
]);

it('gives the editor a Block as a customBlock it knows, with nothing to edit', function () {
    $entry = savedArticle(['body' => doc(aBlock())]);

    $state = editorPage(['record' => $entry->id])->get('data.body');

    expect($state['content'][0]['type'])->toBe('customBlock')
        ->and($state['content'][0]['attrs']['config'] ?? null)->toBeNull()
        ->and($state['content'][0]['attrs']['label'])->toBe('Block unavailable for now: gallery');
});

it('normalises forged form state', function () {
    $entry = savedArticle(['body' => doc(para('a'))]);

    editorPage(['record' => $entry->id])
        ->set('data.body', doc(linkedPara('javascript:alert(1)'), ['type' => 'customBlock', 'attrs' => ['id' => 'not json']], ['type' => 'table', 'content' => [para('b')]]))
        ->call('save')
        ->assertHasNoFormErrors();

    expect($entry->fresh()->body->toHtml())->toBe('<p>link</p><p>b</p>');
});

it('stores a blank form as null', function () {
    $entry = savedArticle();

    editorPage(['record' => $entry->id])->call('save')->assertHasNoFormErrors();

    expect(DB::table('kami_type_article')->where(['entry_id' => $entry->id, 'locale' => 'fr'])->value('body'))->toBeNull();
});

it('leaves a Translation with a fallen-back document blank in the form', function () {
    $entry = savedArticle(['body' => doc(para('Bonjour'))]);
    savedArticle(['title' => 'Hello'], 'en', $entry);

    editorPage(['record' => $entry->id, 'locale' => 'en'])->call('save')->assertHasNoFormErrors();

    expect(DB::table('kami_type_article')->where(['entry_id' => $entry->id, 'locale' => 'en'])->value('body'))->toBeNull();
});

it('offers only what the Field allows in its toolbar', function (RichText $field, array $buttons) {
    $input = RichTextInput::make($field)->container(FilamentSchema::make(editorPage(['record' => savedArticle()->id])->instance()));

    expect(array_merge(...$input->getToolbarButtons()))->toBe($buttons)
        ->and($input->hasFileAttachments())->toBeFalse();
})->with([
    'by default' => [fn () => RichText::make('body'), ['bold', 'italic', 'link', 'h2', 'h3', 'h4', 'blockquote', 'bulletList', 'orderedList', 'horizontalRule', 'undo', 'redo']],
    'with code, narrowed' => [fn () => RichText::make('body')->code()->headings([3]), ['bold', 'italic', 'link', 'code', 'h3', 'blockquote', 'codeBlock', 'bulletList', 'orderedList', 'horizontalRule', 'undo', 'redo']],
]);

it('renders no tool outside the allowed set', function () {
    editorPage(['record' => savedArticle()->id])
        ->assertSeeHtml('toggleBold()')
        ->assertSeeHtml('toggleCodeBlock()')
        ->assertDontSeeHtml('toggleUnderline()')
        ->assertDontSeeHtml('toggleStrike()')
        ->assertDontSeeHtml('toggleSubscript()')
        ->assertDontSeeHtml('toggleSuperscript()')
        ->assertDontSeeHtml('level: 1 ');
});
