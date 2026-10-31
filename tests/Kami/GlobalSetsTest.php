<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Fields\Group;
use Psyao\Kami\Content\Fields\Text;
use Psyao\Kami\Content\GlobalSet;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Content\SiteSettings;
use Psyao\Kami\Facades\Kami;
use Psyao\Kami\Sites\Internal\Site;
use Workbench\App\Content\Footer;
use Workbench\App\Content\Page;

uses(RefreshDatabase::class);

/** Tries to take the URLs a Global Set never has. */
final class RoutableSet extends GlobalSet
{
    #[Override]
    protected bool $routable = true;

    #[Override]
    protected bool $translatable = false;
}

final class PrefixedSet extends GlobalSet
{
    #[Override]
    protected ?string $prefix = 'contact';
}

final class RequiredSet extends GlobalSet
{
    public function fields(): array
    {
        return [Text::make('phone')->required()];
    }
}

final class RequiredInGroupSet extends GlobalSet
{
    public function fields(): array
    {
        return [Group::make('address', [Text::make('city')->required()])];
    }
}

final class RequiredWithDefaultSet extends GlobalSet
{
    public function fields(): array
    {
        return [Text::make('phone')->required()->default('none')];
    }
}

it('is never routable, whatever it declares', function () {
    config(['kami.types' => [RoutableSet::class]]);

    $entry = resolve(SaveEntry::class)->handle(RoutableSet::class, []);

    expect($entry->url())->toBeNull()
        ->and(DB::table('kami_paths')->count())->toBe(0);
});

it('refuses a Prefix, which it could never answer at', function () {
    config(['kami.types' => [PrefixedSet::class]]);

    resolve(ContentTypes::class)->all();
})->throws(InvalidArgumentException::class, 'PrefixedSet: a Global Set has no URL');

it('refuses a required Field with no default, which kami:install could not publish', function (string $type, string $field) {
    config(['kami.types' => [$type]]);

    expect(fn () => resolve(ContentTypes::class)->all())->toThrow(InvalidArgumentException::class, "the Field \"{$field}\" is required with no default");
})->with([[RequiredSet::class, 'phone'], [RequiredInGroupSet::class, 'address']]);

it('accepts a required Field with a default', function () {
    config(['kami.types' => [RequiredWithDefaultSet::class]]);

    expect(resolve(ContentTypes::class)->all())->toHaveKey(RequiredWithDefaultSet::class);
});

it('is always published, titled with its label, whoever saves it', function () {
    $footer = resolve(SaveEntry::class)->handle(Footer::class, ['tagline' => 'Bonjour'], locale: 'fr');
    resolve(SaveEntry::class)->handle(Footer::class, ['tagline' => 'Hello'], $footer, 'en');

    expect($footer->translations->pluck('status', 'locale')->all())->toBe(['fr' => 'published'])
        ->and($footer->fresh()->translations->pluck('status', 'locale')->sortKeys()->all())->toBe(['en' => 'published', 'fr' => 'published'])
        ->and($footer->title)->toBe('Footer');
});

it('refuses a status or a publication date', function (string $attribute, string $value) {
    resolve(SaveEntry::class)->handle(Footer::class, [$attribute => $value], locale: 'fr');
})->with([['status', 'draft'], ['published_at', '2030-01-01']])->throws(ValidationException::class);

it('reads its Entry whatever its visibility', function () {
    expect(Footer::entry())->toBeNull();

    $footer = resolve(SaveEntry::class)->handle(Footer::class, ['tagline' => 'Bonjour'], locale: 'fr');
    DB::table('kami_translations')->where('entry_id', $footer->id)->update(['status' => 'draft']);

    expect(Entry::query()->ofType(Footer::class)->exists())->toBeFalse()
        ->and(Footer::entry()?->tagline)->toBe('Bonjour');
});

it('falls back to the default Locale for a blank value', function () {
    resolve(SaveEntry::class)->handle(Footer::class, ['tagline' => 'Bonjour'], locale: 'fr');
    Site::useLocale('en');

    expect(Footer::entry()?->tagline)->toBe('Bonjour');
});

it('stays within an exact query count in a view', function () {
    Kami::routes();
    resolve(SaveEntry::class)->handle(Footer::class, ['tagline' => 'Merci de votre visite'], locale: 'fr');
    resolve(SaveEntry::class)->handle(Page::class, ['title' => 'À propos', 'status' => 'published'], locale: 'fr');

    DB::enableQueryLog();
    $this->get('/a-propos')->assertOk()->assertSee('Merci de votre visite');

    // The page's Path, Entry, Translations and type rows; the Global Set's Entry, Translations and type rows.
    expect(DB::getQueryLog())->toHaveCount(7);
});

describe('SiteSettings', function () {
    it('is registered without being listed, and listed twice stays one', function () {
        config(['kami.types' => []]);
        expect(resolve(ContentTypes::class)->find('site_settings'))->toBeInstanceOf(SiteSettings::class);

        config(['kami.types' => [SiteSettings::class]]);
        expect(resolve(ContentTypes::class)->all())->toHaveCount(1);
    });

    it('is written with SaveEntry and read with entry()', function () {
        resolve(SaveEntry::class)->handle(SiteSettings::class, ['site_name' => 'Kami'], locale: 'fr');

        expect(SiteSettings::entry()?->site_name)->toBe('Kami');
    });

    it('asks the doctor for no label: the package ships them', function () {
        config(['kami.types' => []]);

        $this->artisan('kami:doctor')->doesntExpectOutputToContain('site_settings');
    });

    it('needs no migration of the site', function () {
        $this->artisan('kami:make:migration', ['type' => 'site_settings'])->doesntExpectOutputToContain('create_kami_type_site_settings');
    });
});
