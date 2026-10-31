<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Internal\ContentModelDrift;
use Workbench\App\Content\Article;
use Workbench\App\Content\Footer;
use Workbench\App\Content\Home;
use Workbench\App\Content\Label;
use Workbench\App\Content\Page;
use Workbench\App\Content\Project;
use Workbench\App\Content\Tag;
use Workbench\App\Content\Testimonial;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Every Workbench table has its type: the suite migrates them all.
    config(['kami.types' => [Article::class, Footer::class, Home::class, Label::class, Page::class, Project::class, Tag::class, Testimonial::class]]);
});

it('reports nothing on tables that match the declarations', function () {
    $this->artisan('kami:doctor')->doesntExpectOutputToContain('Guide: docs/guide/content-types.md#content-model-drift');
});

it('reports the same errors as kami:install, and not what the Paths would do', function () {
    resolve(SaveEntry::class)->handle(Page::class, ['title' => 'About', 'slug' => 'about', 'status' => 'published'], locale: 'en');
    DB::table('kami_translations')->update(['locale' => '*']);

    $this->artisan('kami:doctor')
        ->expectsOutputToContain('The type page is translatable, but its Entries hold a Shared Translation')
        ->doesntExpectOutputToContain('Paths do not follow')
        ->assertFailed();
});

it('reports each column and table that disagrees, on this engine', function () {
    Schema::create('kami_type_ghost', fn (Blueprint $table) => $table->string('locale'));
    Schema::table('kami_type_page', fn (Blueprint $table) => $table->string('stray')->nullable());
    Schema::table('kami_type_page', fn (Blueprint $table) => $table->dropColumn('body'));
    Schema::table('kami_type_label', function (Blueprint $table): void {
        $table->dropColumn('colour');
    });
    Schema::table('kami_type_label', fn (Blueprint $table) => $table->integer('colour')->nullable());

    $this->artisan('kami:doctor')
        ->expectsOutputToContain('The table kami_type_ghost belongs to no declared type')
        ->expectsOutputToContain('kami_type_page.stray belongs to no Field of the type page')
        ->expectsOutputToContain('The Field body of the type page has no column')
        ->expectsOutputToContain('kami_type_label.colour has type integer')
        ->assertFailed();
});

it('runs every check under a table prefix', function () {
    config(['database.connections.prefixed' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'app_'], 'database.default' => 'prefixed']);
    $this->artisan('migrate', ['--path' => [dirname(__DIR__, 2) . '/database/migrations', dirname(__DIR__, 2) . '/workbench/database/migrations'], '--realpath' => true])->assertSuccessful();
    $page = resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Kami', 'slug' => 'kami']);
    DB::table('kami_references')->insert(['id' => (string) Str::ulid(), 'source_entry_id' => $page->id, 'field' => 'relatd', 'target_entry_id' => $page->id, 'position' => 0]);

    $drift = resolve(ContentModelDrift::class);
    $messages = fn (array $problems): string => implode(' ', array_column($problems, 'message'));

    expect($drift->problems())->toBe([])
        ->and($messages($drift->strandedValues()))->toContain('are under relatd, which no relation Field declares');

    Schema::create('kami_type_ghost', fn (Blueprint $table) => $table->string('locale'));

    expect($messages($drift->problems()))->toContain('The table app_kami_type_ghost belongs to no declared type');
});

it('warns about a value left on the row a Field no longer reads, without failing', function () {
    $page = resolve(SaveEntry::class)->handle(Page::class, ['title' => 'About', 'slug' => 'about', 'body' => 'Kept'], locale: 'en');
    DB::table('kami_type_page')->where('entry_id', $page->id)->update(['locale' => '*']);

    $this->artisan('kami:doctor')
        ->expectsOutputToContain('The Field body of the type page is translatable, but holds Shared values')
        ->expectsOutputToContain("makeTranslatable('page', 'body')")
        ->assertSuccessful();
});

it('warns about a link under a handle no relation Field declares, as a mistyped renameField leaves', function () {
    $save = resolve(SaveEntry::class);
    $project = $save->handle(Project::class, ['title' => 'Kami', 'slug' => 'kami']);
    $page = $save->handle(Page::class, ['title' => 'About', 'slug' => 'about'], locale: 'en');
    DB::table('kami_references')->insert(['id' => (string) Str::ulid(), 'source_entry_id' => $project->id, 'field' => 'relatd', 'target_entry_id' => $page->id, 'position' => 0]);

    $this->artisan('kami:doctor')
        ->expectsOutputToContain('are under relatd, which no relation Field declares')
        ->expectsOutputToContain("ContentMigrator::renameField('project', 'relatd', '<handle>')")
        ->assertSuccessful();
});

it('warns about a relation Field flipped without conversion', function () {
    $save = resolve(SaveEntry::class);
    $project = $save->handle(Project::class, ['title' => 'Kami', 'slug' => 'kami']);
    $page = $save->handle(Page::class, ['title' => 'About', 'slug' => 'about'], locale: 'en');
    DB::table('kami_references')->insert(['id' => (string) Str::ulid(), 'source_entry_id' => $project->id, 'field' => 'related', 'locale' => 'en', 'target_entry_id' => $page->id, 'position' => 0]);

    $this->artisan('kami:doctor')
        ->expectsOutputToContain('The Field related of the type project is Shared, but holds links in a Locale')
        ->expectsOutputToContain("makeShared('project', 'related', keep:")
        ->assertSuccessful();
});
