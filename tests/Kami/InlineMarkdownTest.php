<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Psyao\Kami\Admin\Internal\EntryResource\EditEntry;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Fields\Group;
use Psyao\Kami\Content\Fields\Repeater;
use Psyao\Kami\Content\Fields\Text;
use Psyao\Kami\Content\Fields\Textarea;
use Psyao\Kami\Content\Internal\ContentTypes;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

/** On Article's handle and table, its `summary` declared by each test. */
final class ArticleWithLede extends ContentType
{
    public static Text | Textarea $summary;

    /** @var array<mixed> */
    public static array $others = [];

    #[Override]
    protected ?string $prefix = 'articles';

    public function handle(): string
    {
        return 'article';
    }

    public function fields(): array
    {
        return [self::$summary, ...self::$others]; // @phpstan-ignore return.type
    }
}

function lede(Text | Textarea $field, ?string $value, string $locale = 'fr', ?Entry $entry = null): Entry
{
    ArticleWithLede::$summary = $field->inlineMarkdown();
    config(['kami.types' => [ArticleWithLede::class]]);

    $saved = resolve(SaveEntry::class)->handle(ArticleWithLede::class, ['title' => 'Bonjour', 'status' => 'published', 'summary' => $value], $entry, $locale);

    return Entry::query()->findOrFail($saved->id);
}

/** @param  array<string, mixed>  $parameters */
function ledeEditor(array $parameters): Testable
{
    // Filament opens its panel to a User that is not a `FilamentUser` in the local environment only.
    config(['app.env' => 'local']);
    test()->actingAs(tap(User::forceCreate(['name' => 'Ada Lovelace', 'email' => uniqid() . '@example.test', 'password' => 'secret']), fn (User $user) => DB::table('kami_role_user')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->getKey(), 'role' => 'administrator'])));
    Filament::setCurrentPanel('admin');
    Filament::setCurrentResourceConfigurationKey('article');

    return Livewire::test(EditEntry::class, $parameters);
}

beforeEach(fn () => ArticleWithLede::$others = []);

it('keeps only emphasis, strong, inline code and safe links', function (string $raw, string $html) {
    expect(lede(Text::make('summary'), $raw)->summary->toHtml())->toBe($html);
})->with([
    'emphasis' => ['*a*', '<em>a</em>'],
    'strong' => ['**a**', '<strong>a</strong>'],
    'inline code' => ['`a`', '<code>a</code>'],
    'https' => ['[a](https://example.test)', '<a href="https://example.test">a</a>'],
    'http' => ['[a](http://example.test)', '<a href="http://example.test">a</a>'],
    'mailto' => ['[a](mailto:a@example.test)', '<a href="mailto:a@example.test">a</a>'],
    'raw HTML' => ['a <b>x</b> <script>y</script>', 'a x y'],
    'HTML in code' => ['`<b>`', '<code>&lt;b&gt;</code>'],
    'javascript' => ['[a](javascript:alert(1))', 'a'],
    'tel' => ['[a](tel:+41)', 'a'],
    'ftp' => ['[a](ftp://example.test)', 'a'],
    'ftp autolink' => ['<ftp://example.test>', 'ftp://example.test'],
    'data' => ['[a](data:image/png;base64,x)', 'a'],
    'relative' => ['[a](/about)', 'a'],
    'anchor' => ['[a](#about)', 'a'],
    'kami' => ['[a](kami://entry/1)', 'a'],
    'emphasis in a refused link' => ['[*a*](tel:+41)', '<em>a</em>'],
    'image' => ['![a](https://example.test/i.png)', 'a'],
    'image in a link' => ['[![a](https://example.test/i.png)](https://example.test)', '<a href="https://example.test">a</a>'],
    'strikethrough' => ['~~a~~', '~~a~~'],
    'bare web address' => ['www.example.test', 'www.example.test'],
    'bare URL' => ['https://example.test', 'https://example.test'],
    'angle autolink' => ['<https://example.test>', '<a href="https://example.test">https://example.test</a>'],
    'table' => ["| a |\n|---|", "| a |\n|---|"],
    'no block' => ['# a', '# a'],
]);

it('stores the raw value', function () {
    $entry = lede(Text::make('summary'), '*a*');

    expect(DB::table('kami_type_article')->where('entry_id', $entry->id)->value('summary'))->toBe('*a*');
});

it('breaks a Textarea\'s lines, and leaves a Text\'s alone', function (Text | Textarea $field, string $raw, string $html) {
    expect(lede($field, $raw)->summary->toHtml())->toBe($html);
})->with([
    'Textarea' => [Textarea::make('summary'), "*a*\nb", "<em>a</em><br>\nb"],
    'Textarea, CRLF' => [Textarea::make('summary'), "a\r\nb", "a<br>\nb"],
    'Textarea, a blank line' => [Textarea::make('summary'), "*a*\n\nb", "<em>a</em><br>\n<br>\nb"],
    'Textarea, one line' => [Textarea::make('summary'), 'a', 'a'],
    'Textarea, a hard break stays one' => [Textarea::make('summary'), "a  \nb", "a<br />\nb"],
    'Text' => [Text::make('summary'), "a\nb", "a\nb"],
]);

it('outputs its HTML through Blade\'s echo', function () {
    expect(Blade::render('{{ $v }}', ['v' => lede(Text::make('summary'), '*a*')->summary]))->toBe('<em>a</em>');
});

it('reads its text without the syntax', function (string $raw, string $text) {
    expect(lede(Textarea::make('summary'), $raw)->summary->text())->toBe($text);
})->with([
    'marks' => ['*a* **b** `c`', 'a b c'],
    'link' => ['[a](https://example.test)', 'a'],
    'lines' => ["a\nb", 'a b'],
    'entities' => ['a & `<b>`', 'a & <b>'],
]);

it('reads a blank value as an empty one', function (?string $raw) {
    $read = lede(Text::make('summary'), $raw)->summary;

    expect($read->isEmpty())->toBeTrue()->and($read->toHtml())->toBe('')->and($read->text())->toBe('');
})->with(['null' => [null], 'nothing' => [''], 'only stripped HTML' => ['<b></b>']]);

it('is not empty with text', function () {
    expect(lede(Text::make('summary'), 'a')->summary->isEmpty())->toBeFalse();
});

it('carries lang on a span when it falls back', function () {
    $entry = lede(Text::make('summary'), '*Bonjour*');
    lede(Text::make('summary'), null, 'en', $entry);

    $read = Entry::query()->findOrFail($entry->id);
    $read->readIn('en');

    expect($read->fallbackLocale('summary'))->toBe('fr')
        ->and($read->summary->toHtml())->toBe('<span lang="fr"><em>Bonjour</em></span>')
        ->and($read->summary->text())->toBe('Bonjour');
});

it('shows and saves the raw value in the form', function () {
    $entry = lede(Text::make('summary'), '*a* <b>');

    $page = ledeEditor(['record' => $entry->id])->assertSet('data.summary', '*a* <b>');
    $page->set('data.summary', '**b**')->call('save')->assertHasNoFormErrors();

    expect(DB::table('kami_type_article')->where(['entry_id' => $entry->id, 'locale' => 'fr'])->value('summary'))->toBe('**b**');
});

it('leaves a blank value blank in the form', function () {
    ledeEditor(['record' => lede(Text::make('summary'), null)->id])->assertSet('data.summary', null);
});

it('leaves a Translation with a fallen-back value blank in the form', function () {
    $entry = lede(Text::make('summary'), '*a*');
    lede(Text::make('summary'), null, 'en', $entry);

    ledeEditor(['record' => $entry->id, 'locale' => 'en'])->assertSet('data.summary', null);
});

it('refuses a declaration at first read', function (array $others, string $message) {
    ArticleWithLede::$summary = Text::make('summary')->shared()->inlineMarkdown();
    ArticleWithLede::$others = $others;
    config(['kami.types' => [ArticleWithLede::class]]);

    expect(fn () => resolve(ContentTypes::class)->all())->toThrow(InvalidArgumentException::class, $message);
})->with([
    'in a Repeater' => [[Repeater::make('rows', [Text::make('note')->inlineMarkdown()])], 'cannot hold the inline Markdown "note"'],
    'in a Group' => [[Group::make('box', [Textarea::make('note')->inlineMarkdown()])], 'cannot hold the inline Markdown "note"'],
    'as a condition\'s target' => [[Text::make('note')->visibleWhenFilled('summary')], 'whose value a condition cannot read'],
]);

it('leaves a Field without it reading a string', function () {
    expect(Text::make('a')->read('*a*'))->toBe('*a*')->and(Textarea::make('a')->read(null))->toBeNull();
});
