<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Fields\Number;
use Psyao\Kami\Content\Fields\Text;
use Psyao\Kami\Content\Fields\Textarea;
use Psyao\Kami\Content\Internal\ContentModelDrift;
use Psyao\Kami\Content\Singleton;
use Psyao\Kami\Schema\ContentMigrator;
use Workbench\App\Content\Footer;
use Workbench\App\Content\Home;
use Workbench\App\Content\Label;
use Workbench\App\Content\Page;

/** The Workbench's Page, with what a deploy changed in its declaration. */
abstract class DriftedPage extends ContentType
{
    #[Override]
    public function handle(): string
    {
        return 'page';
    }
}

final class PageWithSubtitle extends DriftedPage
{
    public function fields(): array
    {
        return [Textarea::make('body'), Text::make('subtitle')];
    }
}

final class PageWithoutBody extends DriftedPage
{
    public function fields(): array
    {
        return [];
    }
}

final class PageWithNumberBody extends DriftedPage
{
    public function fields(): array
    {
        return [Number::make('body')];
    }
}

final class SharedPage extends DriftedPage
{
    #[Override]
    protected bool $translatable = false;

    public function fields(): array
    {
        return [Textarea::make('body')];
    }
}

/** The Workbench's Label, untranslatable, after a deploy that made it translatable. */
final class TranslatableLabel extends ContentType
{
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

/** A Page flipped to a Singleton, with the Entries it already has. */
final class SingletonPage extends Singleton
{
    #[Override]
    public function handle(): string
    {
        return 'page';
    }

    public function fields(): array
    {
        return [Textarea::make('body')];
    }
}

function deployInstall(): mixed
{
    return test()->artisan('kami:install', ['--email' => 'admin@example.test', '--name' => 'Admin', '--no-interaction' => true]);
}

/** The output of a `kami:install` that must fail: one line may hold several of the things a test looks for. */
function failedInstall(): string
{
    expect(Artisan::call('kami:install', ['--email' => 'admin@example.test', '--name' => 'Admin', '--no-interaction' => true]))->toBe(1);

    return Artisan::output();
}

/** A first deploy of `$before`, with its Pages, and the declarations of the next one. */
function deployed(array $before, array $after, ?Closure $content = null): void
{
    test()->bootWithTypes($before);
    config(['kami.sites.default.locales' => ['fr', 'en']]);
    deployInstall()->assertSuccessful();
    $content?->__invoke(resolve(SaveEntry::class));
    config(['kami.types' => $after]);
}

function pages(SaveEntry $save): void
{
    $save->handle(Page::class, ['title' => 'One', 'slug' => 'one', 'status' => 'published', 'body' => 'Kept'], locale: 'fr');
    $save->handle(Page::class, ['title' => 'Two', 'slug' => 'two', 'status' => 'published', 'body' => 'Kept'], locale: 'fr');
}

it('passes a deploy whose declarations and tables agree', function () {
    deployed([Page::class, Label::class, Home::class], [Page::class, Label::class, Home::class], pages(...));

    deployInstall()->assertSuccessful();
});

it('fails on a declared Field with no column, before any seed or Path', function () {
    deployed([Page::class], [PageWithSubtitle::class, Home::class], pages(...));
    DB::table('kami_paths')->where('path', 'one')->delete();
    Schema::create('kami_type_home', function (Blueprint $table): void {
        $table->foreignUlid('entry_id');
        $table->string('locale', 20);
        $table->string('intro')->nullable();
    });

    $output = failedInstall();

    expect($output)->toContain('The Field subtitle of the type page has no column', 'kami:make:migration page');

    expect(DB::table('kami_entries')->where('type', 'home')->exists())->toBeFalse()
        ->and(DB::table('kami_paths')->where('path', 'one')->exists())->toBeFalse();
});

it('fails on a declared Field whose table does not exist', function () {
    test()->bootWithTypes([Home::class]);
    config(['kami.sites.default.locales' => ['fr', 'en']]);
    deployInstall()->assertSuccessful();
    config(['kami.types' => [Home::class, Page::class]]);

    $output = failedInstall();

    expect($output)->toContain('The Field body of the type page has no column', 'kami:make:migration page');
});

it('fails on a column with no Field, and removing the Field destroys nothing', function () {
    deployed([Page::class], [PageWithoutBody::class], pages(...));

    $output = failedInstall();

    expect($output)->toContain('kami_type_page.body belongs to no Field of the type page', 'kami:make:migration page');

    expect(DB::table('kami_type_page')->where('body', 'Kept')->count())->toBe(2)
        ->and(DB::table('kami_paths')->where('status', 200)->count())->toBe(2);
});

it('fails on a wrong column type', function () {
    deployed([Page::class], [PageWithNumberBody::class]);

    $output = failedInstall();

    expect($output)->toContain('kami_type_page.body has type', 'kami:make:migration page');
});

it('fails on a type table with no declared type', function () {
    deployed([Page::class], []);

    $output = failedInstall();

    expect($output)->toContain('The table kami_type_page belongs to no declared type', "ContentMigrator::dropType('page')", 'ContentMigrator::renameType');
});

it('fails on a type flipped to untranslatable, naming the calls, and deletes no 200 row (#135)', function () {
    deployed([Page::class], [SharedPage::class], pages(...));

    $output = failedInstall();

    expect($output)->toContain('The type page is not translatable, but its Entries hold Locale Translations', "ContentMigrator::makeTypeShared('page', keep: 'fr')", "ContentMigrator::makeShared('page', 'body', keep: 'fr')");

    expect(DB::table('kami_paths')->where('status', 200)->count())->toBe(2);
});

it('fails on a type flipped to translatable, naming the calls, and deletes no 200 row (#135)', function () {
    deployed([Label::class], [TranslatableLabel::class], function (SaveEntry $save): void {
        $save->handle(Label::class, ['title' => 'Red', 'slug' => 'red', 'status' => 'published', 'colour' => 'red']);
    });
    $paths = DB::table('kami_paths')->where('status', 200)->count();

    $output = failedInstall();

    expect($output)->toContain('The type label is translatable, but its Entries hold a Shared Translation', "ContentMigrator::makeTypeTranslatable('label', locale: 'fr')", "ContentMigrator::makeTranslatable('label', 'colour')");

    expect($paths)->toBeGreaterThan(0)
        ->and(DB::table('kami_paths')->where('status', 200)->count())->toBe($paths);
});

it('passes once the migration ran the named calls', function () {
    deployed([Page::class], [SharedPage::class], pages(...));

    ContentMigrator::makeTypeShared('page', keep: 'fr');
    ContentMigrator::makeShared('page', 'body', keep: 'fr');

    deployInstall()->assertSuccessful();

    expect(DB::table('kami_paths')->where('status', 200)->count())->toBe(4);
});

it('fails on a Singleton with several Entries, not on clashes', function () {
    deployed([Page::class], [SingletonPage::class], pages(...));

    $output = failedInstall();

    expect($output)->toContain('The Singleton page has several Entries')
        ->not->toContain('Change one of the two slugs');

    expect(DB::table('kami_paths')->where('status', 200)->count())->toBe(2);
});

it('fails on a Global Set with several Entries', function () {
    deployed([Footer::class], [Footer::class]);
    DB::table('kami_entries')->insert(DB::table('kami_entries')->where('type', 'footer')->get()->map(fn (object $entry): array => [...(array) $entry, 'id' => strtolower((string) Str::ulid())])->all());

    expect(failedInstall())->toContain('The Singleton footer has several Entries');
});

it('costs the same number of queries at any volume', function () {
    $queries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        resolve(ContentModelDrift::class)->problems();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    deployed([Page::class, Home::class], [Page::class, Home::class]);
    $empty = $queries();

    foreach (range(1, 20) as $i) {
        resolve(SaveEntry::class)->handle(Page::class, ['title' => "Page {$i}", 'slug' => "page-{$i}", 'body' => 'x'], locale: 'fr');
    }

    expect($queries())->toBe($empty);
});
