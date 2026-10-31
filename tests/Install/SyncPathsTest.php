<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Fields\Text;
use Psyao\Kami\Content\Fields\Textarea;
use Workbench\App\Content\Label;
use Workbench\App\Content\Page;

/** The Workbench's Page after a deploy that gave it a Prefix: same handle, same table. */
final class PageUnderPages extends ContentType
{
    #[Override]
    protected ?string $prefix = 'pages';

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

/** A Page whose new Prefix lands on the Labels' addresses. */
final class PageUnderLabel extends ContentType
{
    #[Override]
    protected ?string $prefix = 'label';

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

/** The Workbench's Label moved under `labels`, freeing the addresses under `label`. */
final class LabelUnderLabels extends ContentType
{
    #[Override]
    protected ?string $prefix = 'labels';

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

/** A Site in French and English with Pages and Labels, installed once. */
function installedSite(array $locales = ['fr', 'en']): void
{
    test()->bootWithTypes([Page::class, Label::class]);
    config(['kami.sites.default.locales' => $locales]);
    install()->assertSuccessful();
}

/** @param  array<string, mixed>  $values */
function saved(string $type, array $values, ?Entry $entry = null, ?string $locale = null): Entry
{
    return resolve(SaveEntry::class)->handle($type, ['status' => 'published', ...$values], $entry, $locale);
}

/** @return array<string, string> The 200 rows, as `locale path` => entry id. */
function paths(): array
{
    return DB::table('kami_paths')->where('status', 200)->orderBy('locale')->orderBy('path')->get()
        ->mapWithKeys(fn (object $row) => ["{$row->locale} {$row->path}" => $row->entry_id])->all();
}

it('moves Entries to their new addresses after a Prefix change', function () {
    installedSite();
    $about = saved(Page::class, ['title' => 'À propos', 'slug' => 'a-propos'], locale: 'fr');
    saved(Page::class, ['title' => 'About', 'slug' => 'about'], $about, 'en');
    $label = saved(Label::class, ['title' => 'Urgent']);
    config(['kami.types' => [PageUnderPages::class, Label::class]]);

    install()->expectsOutputToContain('Paths: 0 created, 2 rewritten, 0 deleted.')->assertSuccessful();

    expect(paths())->toBe([
        'en label/urgent' => $label->id,
        'en pages/about' => $about->id,
        'fr label/urgent' => $label->id,
        'fr pages/a-propos' => $about->id,
    ]);
});

it('gives untranslatable Entries a Path in a new Locale, and nothing else', function () {
    installedSite(['fr']);
    $page = saved(Page::class, ['title' => 'Accueil', 'slug' => 'accueil'], locale: 'fr');
    $label = saved(Label::class, ['title' => 'Urgent']);
    config(['kami.sites.default.locales' => ['fr', 'en']]);

    install()->expectsOutputToContain('Paths: 1 created, 0 rewritten, 0 deleted.')->assertSuccessful();

    expect(paths())->toBe([
        'en label/urgent' => $label->id,
        'fr accueil' => $page->id,
        'fr label/urgent' => $label->id,
    ]);
});

it('reports no change and touches no row when run again', function () {
    installedSite();
    saved(Page::class, ['title' => 'About', 'slug' => 'about'], locale: 'en');
    saved(Label::class, ['title' => 'Urgent']);
    $rows = DB::table('kami_paths')->orderBy('id')->get();

    install()->expectsOutputToContain('Paths: 0 created, 0 rewritten, 0 deleted.')->assertSuccessful();

    expect(DB::table('kami_paths')->orderBy('id')->get())->toEqual($rows);
});

it('deletes the 200 row of a Translation that is gone', function () {
    installedSite();
    $about = saved(Page::class, ['title' => 'À propos', 'slug' => 'a-propos'], locale: 'fr');
    saved(Page::class, ['title' => 'About', 'slug' => 'about'], $about, 'en');
    DB::table('kami_translations')->where(['entry_id' => $about->id, 'locale' => 'en'])->delete();

    install()->expectsOutputToContain('Paths: 0 created, 0 rewritten, 1 deleted.')->assertSuccessful();

    expect(paths())->toBe(['fr a-propos' => $about->id]);
});

it('leaves the rows of an undeclared Locale and of an undeclared type as they are', function () {
    installedSite();
    saved(Page::class, ['title' => 'About', 'slug' => 'about'], locale: 'en');
    saved(Label::class, ['title' => 'Urgent']);
    $rows = paths();
    // A kept table would be drift: the type left with a migration dropping it, and its Entries stayed.
    Schema::drop('kami_type_label');
    config(['kami.types' => [PageUnderPages::class], 'kami.sites.default.locales' => ['fr']]);

    install()->expectsOutputToContain('Paths: 0 created, 0 rewritten, 0 deleted.')->assertSuccessful();

    expect(paths())->toBe($rows);
});

it('fails before the first administrator, naming the address, when another Entry holds it', function () {
    installedSite();
    DB::table('kami_role_user')->delete();
    $page = saved(Page::class, ['title' => 'Urgent', 'slug' => 'urgent'], locale: 'fr');
    $label = saved(Label::class, ['title' => 'Urgent']);
    $rows = paths();
    config(['kami.types' => [PageUnderLabel::class, Label::class]]);

    install()
        ->expectsOutputToContain("Entry {$page->id} cannot take /label/urgent in fr: Entry {$label->id} holds it.")
        ->assertFailed();

    expect(paths())->toBe($rows)
        ->and(DB::table('kami_role_user')->count())->toBe(0);
});

it('moves an Entry onto an address another Entry leaves in the same run', function () {
    installedSite();
    $page = saved(Page::class, ['title' => 'Urgent', 'slug' => 'urgent'], locale: 'fr');
    $label = saved(Label::class, ['title' => 'Urgent']);
    config(['kami.types' => [PageUnderLabel::class, LabelUnderLabels::class]]);

    install()->expectsOutputToContain('Paths: 0 created, 3 rewritten, 0 deleted.')->assertSuccessful();

    expect(paths())->toBe([
        'en labels/urgent' => $label->id,
        'fr label/urgent' => $page->id,
        'fr labels/urgent' => $label->id,
    ]);
});

/** Pages straight into the tables, `fr` only: SaveEntry would take minutes for thousands. */
function seedPages(int $count): void
{
    foreach (array_chunk(range(1, $count), 250) as $chunk) {
        $entries = array_map(fn () => ['id' => strtolower((string) Str::ulid()), 'site' => 'default', 'type' => 'page'], $chunk);
        DB::table('kami_entries')->insert($entries);
        DB::table('kami_translations')->insert(array_map(fn (array $entry) => ['id' => strtolower((string) Str::ulid()), 'entry_id' => $entry['id'], 'locale' => 'fr', 'title' => $entry['id'], 'slug' => $entry['id'], 'status' => 'draft'], $entries));
        DB::table('kami_paths')->insert(array_map(fn (array $entry) => ['id' => strtolower((string) Str::ulid()), 'site' => 'default', 'locale' => 'fr', 'path' => $entry['id'], 'entry_id' => $entry['id'], 'status' => 200], $entries));
    }
}

/** The peak memory `kami:install` adds while rewriting every Page's Path, in bytes. */
function installPeak(string $type): int
{
    config(['kami.types' => [$type, Label::class]]);
    gc_collect_cycles();
    memory_reset_peak_usage();
    $before = memory_get_usage();
    install()->assertSuccessful()->run();

    return memory_get_peak_usage() - $before;
}

it('holds memory flat over a few thousand Entries', function () {
    installedSite(['fr']);
    seedPages(500);
    $small = installPeak(PageUnderPages::class);
    seedPages(3500);
    $large = installPeak(Page::class);

    expect($large - $small)->toBeLessThan(2 * 1024 * 1024);
});
