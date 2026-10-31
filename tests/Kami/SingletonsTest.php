<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Fields\Text;
use Psyao\Kami\Content\Singleton;
use Psyao\Kami\Facades\Kami;
use Psyao\Kami\Routing\Internal\SyncPaths;
use Workbench\App\Content\Home;
use Workbench\App\Content\Label;
use Workbench\App\Content\Testimonial;

uses(RefreshDatabase::class);

/** The Workbench's Label after a deploy that took away its URLs: same handle, same table. */
final class LabelWithoutUrl extends ContentType
{
    #[Override]
    protected bool $routable = false;

    #[Override]
    protected bool $translatable = false;

    #[Override]
    public function handle(): string
    {
        return 'label';
    }

    public function fields(): array
    {
        return [Text::make('colour')];
    }
}

/** A second Singleton on Home's address. */
final class Landing extends Singleton
{
    #[Override]
    protected ?string $prefix = '';
}

/** One `'*'` Translation, at its Prefix in every Locale. */
final class Legal extends Singleton
{
    #[Override]
    protected bool $translatable = false;
}

/** Hidden behind the `en` Locale. */
final class Hidden extends Singleton
{
    #[Override]
    protected ?string $prefix = 'en';
}

/** A Prefix no URL can carry. */
final class Unanswerable extends Singleton
{
    #[Override]
    protected ?string $prefix = 'a b';
}

/** A Singleton with no `$prefix`: at its handle, as any type. */
final class Contact extends Singleton {}

beforeEach(function () {
    Kami::routes();
});

/** @return array<string, string> */
function paths(): array
{
    return DB::table('kami_paths')->orderBy('locale')->pluck('path', 'locale')->all();
}

describe('a type with no URL', function () {
    it('publishes with no slug, stores no Path and answers no URL', function () {
        $entry = resolve(SaveEntry::class)->handle(Testimonial::class, ['title' => 'Great work', 'status' => 'published', 'quote' => 'Superb.'], locale: 'fr');

        expect(DB::table('kami_paths')->count())->toBe(0)
            ->and($entry->slug)->toBeNull()
            ->and($entry->url())->toBeNull()
            ->and($entry->quote)->toBe('Superb.');

        $this->get('/testimonial/great-work')->assertNotFound();
    });

    it('refuses a slug', function () {
        resolve(SaveEntry::class)->handle(Testimonial::class, ['title' => 'Great work', 'slug' => 'great-work'], locale: 'fr');
    })->throws(ValidationException::class, 'slug');

    it('stops answering at once when flipped, and SyncPaths removes its rows, keeping the slug', function () {
        $entry = resolve(SaveEntry::class)->handle(Label::class, ['title' => 'Urgent', 'status' => 'published']);
        $this->get('/label/urgent')->assertOk();

        config(['kami.types' => [LabelWithoutUrl::class]]);

        $this->get('/label/urgent')->assertNotFound();
        expect(Entry::query()->findOrFail($entry->id)->url())->toBeNull();

        expect(resolve(SyncPaths::class)()['deleted'])->toBe(2)
            ->and(DB::table('kami_paths')->count())->toBe(0)
            ->and(Entry::query()->findOrFail($entry->id)->slug)->toBe('urgent');
    });

    it('is skipped by the missing view check', function () {
        config(['kami.types' => [Testimonial::class, Contact::class]]);

        $this->artisan('kami:doctor')
            ->expectsOutputToContain('The Content Type contact has no view kami.contact')
            ->doesntExpectOutputToContain('kami.testimonial');
    });
});

describe('a Singleton', function () {
    it('has its Prefix as its Path in each Locale, with no slug', function () {
        $save = resolve(SaveEntry::class);
        $home = $save->handle(Home::class, ['title' => 'Accueil', 'status' => 'published', 'intro' => 'Bienvenue'], locale: 'fr');
        $save->handle(Home::class, ['title' => 'Home', 'status' => 'published', 'intro' => 'Welcome'], $home, 'en');

        expect(paths())->toBe(['en' => '', 'fr' => ''])
            ->and($home->fresh()->slug)->toBeNull();

        $this->get('/')->assertOk()->assertSee('Bienvenue');
        $this->get('/en')->assertOk()->assertSee('Welcome');
        expect(Home::entry()->url('fr'))->toBe('http://localhost')
            ->and(Home::entry()->url('en'))->toBe('http://localhost/en');
    });

    it('renders in an exact number of queries', function () {
        resolve(SaveEntry::class)->handle(Home::class, ['title' => 'Accueil', 'status' => 'published', 'intro' => 'Bienvenue'], locale: 'fr');

        DB::enableQueryLog();
        $this->get('/')->assertOk();

        // The Path, the Entry with its visibility, its Translations, its type rows.
        expect(DB::getQueryLog())->toHaveCount(4);

        resolve(SaveEntry::class)->handle(Home::class, ['title' => 'Home', 'status' => 'published', 'intro' => 'Welcome'], Home::entry(), 'en');
        DB::flushQueryLog();
        $this->get('/en')->assertOk();

        expect(DB::getQueryLog())->toHaveCount(4);
    });

    it('takes its handle as Prefix when it declares none', function () {
        config(['kami.types' => [Contact::class]]);

        expect(resolve(SaveEntry::class)->handle(Contact::class, ['title' => 'Contact', 'status' => 'published'], locale: 'fr')->url('fr'))->toBe('http://localhost/contact');
    });

    it('refuses a slug', function () {
        resolve(SaveEntry::class)->handle(Home::class, ['title' => 'Accueil', 'slug' => 'accueil'], locale: 'fr');
    })->throws(ValidationException::class, 'slug');

    it('refuses a second Entry, and still updates the first', function () {
        $save = resolve(SaveEntry::class);
        $home = $save->handle(Home::class, ['title' => 'Accueil'], locale: 'fr');

        try {
            $save->handle(Home::class, ['title' => 'Encore'], locale: 'en');
            $this->fail('A second Entry was saved.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('type');
        }

        expect($save->handle(Home::class, ['title' => 'Bienvenue'], $home, 'fr')->title)->toBe('Bienvenue')
            ->and(DB::table('kami_entries')->count())->toBe(1);
    });

    it('reports an address taken on the title, which the form shows', function () {
        config(['kami.types' => [Home::class, Landing::class]]);
        $save = resolve(SaveEntry::class);
        $save->handle(Home::class, ['title' => 'Accueil'], locale: 'fr');

        try {
            $save->handle(Landing::class, ['title' => 'Landing'], locale: 'fr');
            $this->fail('Two Singletons share an address.');
        } catch (ValidationException $exception) {
            expect($exception->errors()['title'])->toBe([__('kami::admin.fields.path_taken')]);
        }
    });

    it('answers in every Locale from one Translation when untranslatable', function () {
        config(['kami.types' => [Legal::class]]);
        $legal = resolve(SaveEntry::class)->handle(Legal::class, ['title' => 'Legal', 'status' => 'published']);

        expect(paths())->toBe(['en' => 'legal', 'fr' => 'legal'])
            ->and($legal->url('fr'))->toBe('http://localhost/legal')
            ->and($legal->url('en'))->toBe('http://localhost/en/legal')
            ->and(Legal::entry()?->id)->toBe($legal->id);
    });

    it('refuses a Prefix that could not answer', function (string $type) {
        config(['kami.types' => [$type]]);

        try {
            resolve(SaveEntry::class)->handle($type, ['title' => 'Nowhere'], locale: 'fr');
            $this->fail('The Singleton was saved.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('title');
        }
    })->with([Hidden::class, Unanswerable::class]);

    it('has its Path rebuilt by SyncPaths', function () {
        resolve(SaveEntry::class)->handle(Home::class, ['title' => 'Accueil', 'status' => 'published'], locale: 'fr');
        DB::table('kami_paths')->delete();

        expect(resolve(SyncPaths::class)()['created'])->toBe(1)
            ->and(paths())->toBe(['fr' => '']);
    });

    it('asks the doctor for no plural label', function () {
        config(['kami.types' => [Contact::class]]);

        $this->artisan('kami:doctor')
            ->expectsOutputToContain('contact.label')
            ->doesntExpectOutputToContain('contact.plural');
    });

    it('reads its visible Entry, or null', function () {
        $save = resolve(SaveEntry::class);

        expect(Home::entry())->toBeNull();

        $home = $save->handle(Home::class, ['title' => 'Accueil'], locale: 'fr');

        expect(Home::entry())->toBeNull();

        $save->handle(Home::class, ['status' => 'published'], $home, 'fr');

        expect(Home::entry()?->id)->toBe($home->id)
            ->and(Home::entry()?->title)->toBe('Accueil');
    });
});
