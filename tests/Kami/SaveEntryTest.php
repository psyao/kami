<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Facades\Kami;
use Workbench\App\Content\Article;
use Workbench\App\Content\ArticleWithDefaults;
use Workbench\App\Content\Label;
use Workbench\App\Content\Page;

uses(RefreshDatabase::class);

/** @param array<string, mixed> $values */
function save(array $values, ?Entry $entry = null, ?string $locale = null, ?User $actor = null): Entry
{
    return resolve(SaveEntry::class)->handle(Article::class, $values, $entry, $locale, $actor);
}

/**
 * The fields a save fails on, or none.
 *
 * @return list<string>
 */
function failures(Closure $save): array
{
    try {
        $save();
    } catch (ValidationException $exception) {
        return array_keys($exception->errors());
    }

    return [];
}

/** A type with no Field, hence no table of its own. */
final class Fieldless extends ContentType
{
    public function fields(): array
    {
        return [];
    }
}

/** An untranslatable type at the root of the Site. */
final class RootLabel extends ContentType
{
    #[Override]
    protected ?string $prefix = '';

    #[Override]
    protected bool $translatable = false;

    public function fields(): array
    {
        return [];
    }
}

it('saves a Draft with a required Field blank and refuses to publish it', function () {
    $draft = save(['title' => 'Draft']);

    expect($draft->status)->toBe('draft')
        ->and(failures(fn () => save(['status' => 'published'], $draft)))->toBe(['author'])
        ->and(save(['status' => 'published', 'author' => 'Ada'], $draft)->status)->toBe('published');
});

it('saves a Draft with no title and requires the title and slug at publication', function () {
    $draft = save([]);

    expect($draft->title)->toBeNull()
        ->and($draft->slug)->toBeNull()
        ->and(failures(fn () => save(['status' => 'published', 'author' => 'Ada'], $draft)))->toBe(['title', 'slug']);
});

it('applies a Field\'s rules on Drafts too', function () {
    expect(failures(fn () => save(['title' => 'Long', 'summary' => str_repeat('a', 201)])))->toBe(['summary']);
});

it('refuses an unknown status and an invalid publication date', function () {
    expect(failures(fn () => save(['title' => 'Odd', 'status' => 'live', 'published_at' => 'soon'])))->toBe(['status', 'published_at']);
});

it('stores blanks as null, so a blank Translatable Field falls back', function () {
    $entry = save(['title' => 'Bonjour', 'summary' => 'Un article.', 'author' => 'Ada', 'status' => 'published'], locale: 'fr');
    save(['title' => 'Hello', 'summary' => '', 'status' => 'published'], $entry, 'en');

    $english = Entry::query()->inLocale('en')->sole();

    expect($english->summary)->toBe('Un article.')
        ->and($english->fallbackLocale('summary'))->toBe('fr')
        ->and(save(['title' => 'Empty', 'summary' => []])->summary)->toBeNull()
        ->and(DB::table('kami_type_article')->where('locale', 'en')->value('summary'))->toBeNull();
});

it('writes a Shared Field given in a Locale call to the Entry\'s shared row', function () {
    $entry = save(['title' => 'Bonjour'], locale: 'fr');
    save(['title' => 'Hello', 'author' => 'Ada'], $entry, 'en');

    expect(Entry::query()->withUnpublished()->inLocale('fr')->sole()->author)->toBe('Ada')
        ->and(DB::table('kami_type_article')->pluck('author', 'locale')->all())->toBe(['*' => 'Ada']);
});

it('writes only Shared Fields in a \'*\' call of a translatable type', function () {
    $entry = save(['title' => 'Bonjour'], locale: 'fr');

    expect(failures(fn () => save(['title' => 'Hello', 'slug' => 'hello', 'status' => 'published', 'published_at' => now(), 'summary' => 'An article.'], $entry, '*')))
        ->toBe(['title', 'slug', 'status', 'published_at', 'summary']);

    save(['author' => 'Ada'], $entry, '*');

    expect(Entry::query()->withUnpublished()->inLocale('fr')->sole()->author)->toBe('Ada')
        ->and(DB::table('kami_translations')->pluck('locale')->all())->toBe(['fr']);
});

it('refuses a Field the type does not declare', function () {
    expect(failures(fn () => save(['title' => 'Odd', 'colour' => 'red'])))->toBe(['colour']);
});

it('writes an untranslatable type\'s values to its \'*\' Translation whatever the Locale', function () {
    $label = resolve(SaveEntry::class)->handle(Label::class, ['title' => 'Urgent', 'colour' => 'red'], locale: 'en');

    expect($label->translations->pluck('locale')->all())->toBe(['*'])
        ->and(DB::table('kami_type_label')->pluck('locale')->all())->toBe(['*']);
});

it('generates the slug from the title and normalises a given one', function () {
    expect(save(['title' => 'Hello World'])->slug)->toBe('hello-world')
        ->and(save(['title' => 'Café', 'slug' => '/Caf%C3%A9/Crème/'])->slug)->toBe("caf\u{e9}/cr\u{e8}me");
});

it('refuses a Path another Translation holds in the same Site and Locale, and leaves no row behind', function () {
    save(['title' => 'Bonjour'], locale: 'fr');

    expect(failures(fn () => save(['title' => 'Bonjour', 'summary' => 'Encore.'], locale: 'fr')))->toBe(['slug'])
        ->and(DB::table('kami_entries')->count())->toBe(1)
        ->and(DB::table('kami_translations')->count())->toBe(1)
        ->and(DB::table('kami_type_article')->count())->toBe(0);
});

it('accepts the same slug in another Locale or another Site', function () {
    DB::table('kami_paths')->insert(['id' => (string) str()->ulid(), 'site' => 'other', 'locale' => 'fr', 'path' => 'articles/hello', 'status' => 410]);

    $french = save(['title' => 'Hello', 'author' => 'Ada', 'status' => 'published'], locale: 'fr');
    $english = save(['title' => 'Hello', 'author' => 'Ada', 'status' => 'published'], locale: 'en');

    expect([$french->url('fr'), $english->url('en')])->toBe(['http://localhost/articles/hello', 'http://localhost/en/articles/hello']);
});

it('schedules, backdates, unpublishes and republishes as ADR 0019 states', function () {
    $this->freezeSecond();
    Kami::routes();

    $scheduled = save(['title' => 'Later', 'author' => 'Ada', 'status' => 'published', 'published_at' => now()->addDay()]);
    $backdated = save(['title' => 'Earlier', 'author' => 'Ada', 'status' => 'published', 'published_at' => now()->subYear()]);
    $unpublished = save(['status' => 'draft'], $backdated);

    expect($scheduled->status)->toBe('published')
        ->and($unpublished->status)->toBe('draft')
        ->and($unpublished->published_at?->toIso8601String())->toBe(now()->subYear()->toIso8601String())
        ->and(save(['status' => 'published'], $unpublished)->published_at?->toIso8601String())->toBe(now()->subYear()->toIso8601String());

    $this->get('/articles/later')->assertNotFound();
    $this->get('/articles/earlier')->assertOk();
});

it('records the Creator and the Last modifier, and accepts no actor', function () {
    $ada = User::forceCreate(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'secret']);
    $grace = User::forceCreate(['name' => 'Grace', 'email' => 'grace@example.test', 'password' => 'secret']);

    $entry = save(['title' => 'Bonjour'], actor: $ada);
    $updated = save(['title' => 'Salut'], $entry, actor: $grace);
    $anonymous = save(['title' => 'Import']);

    expect([$updated->created_by, $updated->updated_by])->toBe([$ada->id, $grace->id])
        ->and([$anonymous->created_by, $anonymous->updated_by])->toBe([null, null]);
});

it('stores a Field\'s default when it creates a Translation, a Shared one only while the Entry has none, and never on update', function () {
    config(['kami.types' => [ArticleWithDefaults::class]]);
    $save = fn (array $values, ?Entry $entry = null, ?string $locale = null): Entry => resolve(SaveEntry::class)->handle('article', $values, $entry, $locale);

    $entry = $save(['title' => 'Bonjour']);
    $blank = $save(['title' => 'Vide', 'summary' => null]);
    $english = $save(['title' => 'Hello'], $entry, 'en');
    $save(['title' => 'Salut', 'author' => null], $entry);

    expect([$entry->summary, $entry->author])->toBe(['To be written.', 'The newsroom'])
        ->and($blank->summary)->toBeNull()
        ->and(Entry::query()->withUnpublished()->inLocale('en')->find($english->id)?->summary)->toBe('To be written.')
        ->and(Entry::query()->withUnpublished()->find($entry->id)?->summary)->toBe('To be written.')
        ->and(Entry::query()->withUnpublished()->find($entry->id)?->author)->toBeNull();
});

it('stores a Boolean once for the whole Entry and reads it as a bool, null until given', function (mixed $given, bool $read) {
    $entry = save(['title' => 'Bonjour']);

    expect($entry->featured)->toBeNull();

    $entry = save(['featured' => $given], $entry);

    expect($entry->featured)->toBe($read)
        ->and(DB::table('kami_type_article')->where('entry_id', $entry->id)->where('locale', '*')->count())->toBe(1);
})->with([
    'true' => [true, true],
    'false' => [false, false],
    'one' => [1, true],
    'zero' => [0, false],
    'form string' => ['1', true],
]);

it('refuses a value that is no boolean', function () {
    expect(failures(fn () => save(['title' => 'Bonjour', 'featured' => 'maybe'])))->toBe(['featured']);
});

it('applies a site Field type\'s baseline rules and cast, under the declaration\'s own rules', function () {
    expect(failures(fn () => save(['title' => 'Bonjour', 'accent' => 'red'])))->toBe(['accent'])
        ->and(save(['title' => 'Bonjour', 'accent' => '#ABCDEF'])->accent)->toBe('#abcdef');
});

it('refuses what a Field type cannot hold, with no extra rule', function (string $field, mixed $value) {
    expect(failures(fn () => save(['title' => 'Bonjour', $field => $value])))->toBe([$field]);
})->with([
    'not a URL' => ['website', 'not a url'],
    'a URL that is not the web, which a link would open' => ['website', 'data://text/html,x'],
    'a URL too long for its column' => ['website', 'https://example.test/' . str_repeat('a', 255)],
    'not an email' => ['contact', 'ada'],
    'an email too long for its column' => ['contact', str_repeat('a', 250) . '@example.test'],
    'not a number' => ['pages', 'many'],
    'a decimal in an integer column' => ['pages', '1.5'],
    'above the integer column' => ['pages', 2147483648],
    'below the integer column' => ['pages', -2147483649],
    'not a decimal' => ['rating', 'high'],
    'too many decimals' => ['rating', '1.234'],
    'above the decimal column' => ['rating', '1000000'],
    'not a date' => ['starts_on', 'soon'],
    'not a date and time' => ['starts_at', 'soon'],
    'an unknown option' => ['kind', 'gossip'],
]);

it('reads each Field type as its cast value, Shared by default', function (string $field, mixed $given, mixed $read) {
    $entry = save(['title' => 'Bonjour', $field => $given]);

    expect($entry->{$field})->toEqual($read)
        ->and(get_debug_type($entry->{$field}))->toBe(get_debug_type($read))
        ->and(DB::table('kami_type_article')->where('entry_id', $entry->id)->where('locale', 'fr')->exists())->toBeFalse()
        ->and(DB::table('kami_type_article')->where('entry_id', $entry->id)->where('locale', '*')->value($field))->not->toBeNull();
})->with([
    'Url' => ['website', 'https://example.test/a', 'https://example.test/a'],
    'Email' => ['contact', 'ada@example.test', 'ada@example.test'],
    'Number' => ['pages', '12', 12],
    'Number at zero' => ['pages', 0, 0],
    'decimal Number, exact and alike on every engine' => ['rating', '12.5', '12.50'],
    'Date' => ['starts_on', '2026-03-15', CarbonImmutable::parse('2026-03-15 00:00:00', 'UTC')],
    'DateTime' => ['starts_at', '2026-03-15 10:30:00', CarbonImmutable::parse('2026-03-15 10:30:00', 'UTC')],
    'Select' => ['kind', 'review', 'review'],
]);

it('stores a DateTime in UTC, whatever the zone it is given in', function (mixed $given) {
    $entry = save(['title' => 'Bonjour', 'starts_at' => $given]);

    expect(DB::table('kami_type_article')->where('entry_id', $entry->id)->value('starts_at'))->toStartWith('2026-03-15 09:30:00')
        ->and($entry->starts_at->utc()->toDateTimeString())->toBe('2026-03-15 09:30:00');
})->with([
    'a string with an offset' => ['2026-03-15T10:30:00+01:00'],
    'a date in another zone' => [CarbonImmutable::parse('2026-03-15 10:30:00', 'Europe/Zurich')],
]);

it('keeps a Date on its calendar day, whatever the zone it is given in', function (mixed $given) {
    $entry = save(['title' => 'Bonjour', 'starts_on' => $given]);

    expect($entry->starts_on->toDateString())->toBe('2026-03-15');
})->with([
    'a date just after midnight in Zurich' => [CarbonImmutable::parse('2026-03-15 00:30:00', 'Europe/Zurich')],
    'a string with an offset' => ['2026-03-15T23:30:00-05:00'],
]);

it('saves again over stored values of every type', function () {
    $entry = save(['title' => 'Bonjour', 'website' => 'https://example.test', 'pages' => 3, 'rating' => '1.50', 'starts_on' => '2026-03-15', 'starts_at' => '2026-03-15 10:30:00', 'kind' => 'news']);

    expect(save(['title' => 'Salut'], $entry)->title)->toBe('Salut');
});

it('updates an Entry of a type with no Field', function () {
    config(['kami.types' => [Fieldless::class]]);
    $entry = resolve(SaveEntry::class)->handle(Fieldless::class, ['title' => 'One']);

    expect(resolve(SaveEntry::class)->handle(Fieldless::class, ['title' => 'Two'], $entry)->title)->toBe('Two');
});

it('refuses a value over its column\'s limit on the Field, and stores one at the limit', function () {
    $page = fn (array $values) => resolve(SaveEntry::class)->handle(Page::class, $values);
    $atLimit = str_repeat('é', 255);
    // 65,535 bytes, what a MySQL `text` column holds: half as many characters.
    $body = str_repeat('é', 32767) . 'a';

    expect(failures(fn () => save(['title' => 'Long', 'author' => str_repeat('é', 256)])))->toBe(['author'])
        ->and(save(['title' => 'Long', 'author' => $atLimit])->author)->toBe($atLimit)
        ->and(failures(fn () => $page(['title' => 'Long', 'body' => $body . 'a'])))->toBe(['body'])
        ->and($page(['title' => 'Long', 'body' => $body])->body)->toBe($body);
});

it('names a Field\'s length limit as Laravel\'s max rule does', function () {
    try {
        resolve(SaveEntry::class)->handle(Page::class, ['title' => 'Long', 'body' => str_repeat('a', 65536)]);
    } catch (ValidationException $exception) {
        expect($exception->errors()['body'][0])->toContain('65535');

        return;
    }

    $this->fail('The value was accepted.');
});

it('refuses an Entry of another type', function () {
    $page = resolve(SaveEntry::class)->handle(Page::class, ['title' => 'A page']);

    expect(fn () => save(['title' => 'An article'], $page))->toThrow(InvalidArgumentException::class, 'page');
});

it('refuses a slug that cannot answer at its URL', function (string $slug) {
    expect(failures(fn () => save(['title' => 'Hello', 'slug' => $slug])))->toBe(['slug']);
})->with(['a?b#c d', 'a b', 'a?b', 'a#b', '100%25', 'a\\b', 'a//b', '../a', 'a/..', '.', "a\u{7f}b", "\xff"]);

it('accepts accents, dots and nested segments in a slug', function (string $slug) {
    expect(save(['title' => 'Hello', 'slug' => $slug])->slug)->toBe($slug);
})->with(["caf\u{e9}", 'a.b', '.well-known', 'a/b', 'a_b-c']);

it('refuses a root type\'s slug starting with another Locale\'s code, in the default Locale only', function () {
    $page = fn (string $slug, ?string $locale = null) => failures(fn () => resolve(SaveEntry::class)->handle(Page::class, ['title' => 'Hello', 'slug' => $slug], locale: $locale));

    expect($page('en'))->toBe(['slug'])
        ->and($page('en/about'))->toBe(['slug'])
        ->and($page('fr'))->toBe([])
        ->and($page('english'))->toBe([])
        ->and($page('en', 'en'))->toBe([])
        ->and(save(['title' => 'Hello', 'slug' => 'en'])->slug)->toBe('en');
});

it('refuses another Locale\'s code for an untranslatable root type, which answers in every Locale', function () {
    config(['kami.types' => [...config('kami.types'), RootLabel::class]]);

    expect(failures(fn () => resolve(SaveEntry::class)->handle(RootLabel::class, ['title' => 'Hello', 'slug' => 'en'])))->toBe(['slug']);
});
