<?php

declare(strict_types=1);

namespace Psyao\Kami\Tests\Install\AguetDev;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Fields\Boolean;
use Psyao\Kami\Content\Fields\Email;
use Psyao\Kami\Content\Fields\Terms;
use Psyao\Kami\Content\Fields\Text;
use Psyao\Kami\Content\Fields\Textarea;
use Psyao\Kami\Content\Fields\Url;
use Psyao\Kami\Content\Singleton;
use Psyao\Kami\Content\Taxonomy;
use Psyao\Kami\Facades\Kami;

// M3's acceptance: aguet.dev's types as docs/research/site-aguet-dev.md describes them. `about_body` stays a Textarea until M4's rich text; `url` becomes `website`, as `Entry::url()` holds the name.

final class Home extends Singleton
{
    #[\Override]
    protected ?string $prefix = '';

    public function fields(): array
    {
        return [
            Textarea::make('hero_title'),
            Text::make('hero_subtitle'),
            Text::make('hero_role'),
            Text::make('hero_location'),
            Text::make('hero_exp'),
            Text::make('hero_focus'),
            Textarea::make('about_body'),
            Text::make('contact_lead'),
            Email::make('contact_email')->shared(),
            Url::make('contact_linkedin')->shared(),
            Text::make('contact_linkedin_label')->shared(),
            Url::make('contact_github')->shared(),
            Text::make('contact_github_label')->shared(),
        ];
    }
}

final class Project extends ContentType
{
    #[\Override]
    protected bool $routable = false;

    #[\Override]
    protected bool $orderable = true;

    public function fields(): array
    {
        return [
            Text::make('client'),
            Text::make('role'),
            Textarea::make('summary'),
            Url::make('website')->shared(),
            Boolean::make('featured')->shared(),
            Terms::make('tags')->of(Tag::class),
        ];
    }
}

final class SkillGroup extends ContentType
{
    #[\Override]
    protected bool $routable = false;

    #[\Override]
    protected bool $orderable = true;

    public function fields(): array
    {
        return [
            Text::make('text'),
            Text::make('note'),
            Boolean::make('focus')->shared(),
            Terms::make('tags')->of(Tag::class),
        ];
    }
}

final class Tag extends Taxonomy
{
    #[\Override]
    protected bool $translatable = false;

    #[\Override]
    protected bool $routable = false;
}

it('declares aguet.dev, and its Home lists Projects by hand with Tags in pick order', function () {
    $this->bootWithKamiOnAPanel();
    config(['kami.sites.default.locales' => ['fr', 'en']]);
    $site = sys_get_temp_dir() . '/kami-aguet-' . uniqid();
    $this->app->useDatabasePath("{$site}/database");
    File::ensureDirectoryExists(database_path('migrations'));
    File::ensureDirectoryExists("{$site}/views/kami");
    File::put("{$site}/views/kami/home.blade.php", <<<'BLADE'
        <h1>{{ $entry->hero_title }}</h1>
        @foreach (\Psyao\Kami\Content\Entry::query()->ofType(\Psyao\Kami\Tests\Install\AguetDev\Project::class)->ordered()->with('tags')->get() as $project)
            <h2>{{ $project->title }}</h2>
            @foreach ($project->tags as $tag)<span>{{ $tag->title }}</span>@endforeach
        @endforeach
        BLADE);
    View::addLocation("{$site}/views");
    $install = ['kami:install', ['--email' => 'admin@example.test', '--name' => 'Admin', '--no-interaction' => true]];

    try {
        $this->artisan(...$install)->assertSuccessful();
        config(['kami.types' => [Home::class, Project::class, SkillGroup::class, Tag::class]]);

        foreach (['home', 'project', 'skill_group'] as $type) {
            $this->artisan('kami:make:migration', ['type' => $type, '--no-interaction' => true])->assertSuccessful();
            // It refuses to work from a schema that its own pending migrations are about to change.
            $this->artisan('migrate')->assertSuccessful();
        }

        $this->artisan(...$install)->assertSuccessful();
        Kami::routes();

        $save = resolve(SaveEntry::class);
        $save->handle(Home::class, ['title' => 'Accueil', 'status' => 'published', 'hero_title' => 'Bonjour'], Entry::query()->withUnpublished()->ofType(Home::class)->sole(), 'fr');

        [$php, $laravel, $vue] = array_map(fn (string $title) => $save->handle(Tag::class, ['title' => $title, 'status' => 'published']), ['PHP', 'Laravel', 'Vue']);
        $save->handle(SkillGroup::class, ['title' => 'Backend', 'status' => 'published', 'tags' => [$php->id]], locale: 'fr');
        $save->handle(Project::class, ['title' => 'CVCI', 'status' => 'published', 'tags' => [$vue->id, $php->id, $laravel->id]], locale: 'fr');
        $save->handle(Project::class, ['title' => 'Animalia', 'status' => 'published', 'tags' => [$laravel->id]], locale: 'fr');

        $this->get('/')->assertOk()->assertSeeInOrder(['Bonjour', 'CVCI', 'Vue', 'PHP', 'Laravel', 'Animalia', 'Laravel']);
        $this->get('/project/cvci')->assertNotFound();
        expect(Entry::query()->ofType(SkillGroup::class)->sole()->tags->pluck('title')->all())->toBe(['PHP']);
    } finally {
        File::deleteDirectory($site);
    }
});
