<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Fields\Entries;
use Psyao\Kami\Content\Fields\Terms;
use Psyao\Kami\Content\Fields\Text;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Content\Taxonomy;
use Psyao\Kami\Content\Term;
use Workbench\App\Content\Article;
use Workbench\App\Content\Home;
use Workbench\App\Content\Page;
use Workbench\App\Content\Project;
use Workbench\App\Content\Tag;

uses(RefreshDatabase::class);

class ClientModel extends Entry
{
    public function host(): string
    {
        return "host of {$this->title}";
    }
}

class TopicModel extends Term {}

final class Client extends ContentType
{
    protected ?string $model = ClientModel::class;
}

final class Topic extends Taxonomy
{
    protected ?string $model = TopicModel::class;
}

final class Region extends Taxonomy {}

/** What each test declares in `$declared`. */
final class Classified extends ContentType
{
    /** @var array<mixed> */
    public static array $declared = [];

    public function fields(): array
    {
        return self::$declared; // @phpstan-ignore return.type
    }
}

final class BadModel extends ContentType
{
    protected ?string $model = stdClass::class; // @phpstan-ignore assign.propertyType
}

final class BadTaxonomyModel extends Taxonomy
{
    protected ?string $model = ClientModel::class;
}

final class HidingTaxonomy extends Taxonomy
{
    public function fields(): array
    {
        return [Text::make('entries')];
    }
}

final class WithRequired extends Taxonomy
{
    public function fields(): array
    {
        return [Text::make('code')->required()];
    }
}

function declaredWith(mixed ...$fields): void
{
    Classified::$declared = $fields;
}

function term(string $title, string $type = Tag::class): Entry
{
    return resolve(SaveEntry::class)->handle($type, ['title' => $title]);
}

/** @param array<string, mixed> $values */
function project(string $title, array $values = [], string $status = 'published'): Entry
{
    return resolve(SaveEntry::class)->handle(Project::class, ['title' => $title, 'status' => $status, ...$values], locale: 'fr');
}

it('reads Terms as Term models, in pick order', function () {
    $first = term('Laravel');
    $second = term('Filament');
    $project = project('Refonte', ['tags' => [$second->id, $first->id]]);

    expect($project->tags)->toHaveCount(2)->each->toBeInstanceOf(Term::class)
        ->and($project->tags->pluck('title')->all())->toBe(['Filament', 'Laravel']);
});

it('hydrates each row as its type\'s model in a mixed listing', function () {
    config(['kami.types' => [Article::class, Client::class, Region::class, Topic::class]]);

    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'status' => 'published', 'author' => 'Ada'], locale: 'fr');
    resolve(SaveEntry::class)->handle(Client::class, ['title' => 'Acme', 'status' => 'published'], locale: 'fr');
    resolve(SaveEntry::class)->handle(Region::class, ['title' => 'Vaud'], locale: 'fr');
    resolve(SaveEntry::class)->handle(Topic::class, ['title' => 'Web'], locale: 'fr');

    $classes = Entry::query()->get()->mapWithKeys(fn (Entry $entry): array => [$entry->type => $entry::class]);

    expect($classes->all())->toEqualCanonicalizing(['article' => Entry::class, 'client' => ClientModel::class, 'region' => Term::class, 'topic' => TopicModel::class]);
    expect(Entry::query()->ofType(Client::class)->sole())
        ->host()->toBe('host of Acme')
        ->translations->toHaveCount(1);
});

it('reads a subclass through its own query as through Entry\'s', function () {
    term('Laravel');

    expect(Term::query()->sole())->toBeInstanceOf(Term::class)->title->toBe('Laravel');
});

it('publishes a Term saved with no status, once, and keeps what is asked', function () {
    $term = term('Laravel');

    expect($term->status)->toBe('published')->and($term->published_at)->not->toBeNull()
        ->and($term->url())->toBeNull();

    $draft = resolve(SaveEntry::class)->handle(Tag::class, ['title' => 'Brouillon', 'status' => 'draft']);
    expect($draft->status)->toBe('draft');

    expect(resolve(SaveEntry::class)->handle(Tag::class, ['title' => 'Encore'], $draft)->status)->toBe('draft');
});

it('still saves another type as a Draft when no status is given', function () {
    expect(resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Esquisse'], locale: 'fr')->status)->toBe('draft');
});

it('keeps one Term for every Locale on an untranslatable Taxonomy', function () {
    $term = term('Laravel');

    expect(DB::table('kami_translations')->where('entry_id', $term->id)->pluck('locale')->all())->toBe(['*']);
});

it('lists a Term\'s visible Entries, once each', function () {
    $tag = term('Laravel');
    $visible = project('Refonte', ['tags' => [$tag->id]]);
    project('Brouillon', ['tags' => [$tag->id]], 'draft');
    project('Sans tag');

    /** @var Term $term */
    $term = Term::query()->findOrFail($tag->id);

    expect($term->entries()->pluck('kami_entries.id')->all())->toBe([$visible->id])
        ->and($term->entries->first())->title->toBe('Refonte')
        ->and($term->entries()->withUnpublished()->count())->toBe(2);
});

it('lists an Entry once when it links a Term through several Fields or Locales', function () {
    config(['kami.types' => [Tag::class, Classified::class]]);
    declaredWith(Terms::make('tags')->of(Tag::class)->translatable(), Terms::make('more')->of(Tag::class)->creatable(false));

    $tag = term('Laravel');
    $entry = resolve(SaveEntry::class)->handle(Classified::class, ['title' => 'Bonjour', 'status' => 'published', 'tags' => [$tag->id], 'more' => [$tag->id]], locale: 'fr');
    resolve(SaveEntry::class)->handle(Classified::class, ['title' => 'Hello', 'status' => 'published', 'tags' => [$tag->id]], $entry, 'en');

    /** @var Term $term */
    $term = Term::query()->findOrFail($tag->id);

    expect($term->entries)->toHaveCount(1)->and($term->entries()->count())->toBe(1);
});

it('loads the Entries of several Terms with no N+1', function () {
    $tags = [term('Un'), term('Deux'), term('Trois')];
    project('Refonte', ['tags' => array_map(fn (Entry $tag): string => $tag->id, $tags)]);
    project('Autre', ['tags' => [$tags[0]->id]]);

    Model::preventLazyLoading();

    try {
        DB::enableQueryLog();
        $terms = Term::query()->ofType(Tag::class)->with('entries')->get();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($terms->firstWhere('id', $tags[0]->id)->entries)->toHaveCount(2)
            ->and($terms->firstWhere('id', $tags[1]->id)->entries)->toHaveCount(1)
            // Terms, their Translations, the Entries, their Translations, Project's table.
            ->and($count)->toBe(5);
    } finally {
        Model::preventLazyLoading(false);
    }
});

it('refuses wrong declarations at first read', function (Closure $declare, string $message) {
    $declare();

    expect(fn () => resolve(ContentTypes::class)->all())->toThrow(InvalidArgumentException::class, $message);
})->with([
    'a $model that is no Entry' => [fn () => config(['kami.types' => [BadModel::class]]), 'must extend ' . Entry::class],
    'a Taxonomy\'s $model that is no Term' => [fn () => config(['kami.types' => [BadTaxonomyModel::class]]), 'must extend ' . Term::class],
    'a Field hidden by the model\'s method' => [fn () => config(['kami.types' => [HidingTaxonomy::class]]), 'names a method of ' . Term::class],
    'a Terms target that is no Taxonomy' => [function () {
        config(['kami.types' => [Article::class, Classified::class]]);
        declaredWith(Terms::make('tags')->of(Article::class));
    }, 'is no Taxonomy'],
    'quick create on several types' => [function () {
        config(['kami.types' => [Tag::class, Region::class, Classified::class]]);
        declaredWith(Terms::make('tags')->of(Tag::class, Region::class));
    }, '->creatable(false)'],
    'quick create needing a required Field' => [function () {
        config(['kami.types' => [WithRequired::class, Classified::class]]);
        declaredWith(Terms::make('tags')->of(WithRequired::class));
    }, '"code"'],
    'quick create of a Singleton' => [function () {
        config(['kami.types' => [Home::class, Classified::class]]);
        declaredWith(Entries::make('home')->of(Home::class)->creatable());
    }, 'one Entry'],
]);

it('accepts what the quick create refusals leave', function () {
    config(['kami.types' => [Tag::class, Region::class, WithRequired::class, Article::class, Page::class, Classified::class]]);
    declaredWith(
        Terms::make('tags')->of(Tag::class),
        Terms::make('both')->of(Tag::class, Region::class)->creatable(false),
        Terms::make('coded')->of(WithRequired::class)->creatable(false),
        Entries::make('related')->of(Article::class, Page::class),
        Entries::make('page')->of(Page::class)->creatable(),
    );

    expect(resolve(ContentTypes::class)->all())->toHaveKey(Classified::class);
});
