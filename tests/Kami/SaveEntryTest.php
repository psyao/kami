<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Facades\Kami;
use Workbench\App\Content\Article;
use Workbench\App\Content\Label;

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
