<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\SiteSettings;
use Workbench\App\Content\Article;
use Workbench\App\Content\Footer;
use Workbench\App\Content\Home;
use Workbench\App\Content\Label;
use Workbench\App\Content\Page;
use Workbench\App\Content\Project;
use Workbench\App\Content\Tag;
use Workbench\App\Content\Testimonial;
use Workbench\App\Models\User;

/** What `composer serve` shows: known accounts and one Entry per state worth looking at. */
class DatabaseSeeder extends Seeder
{
    public function run(SaveEntry $save): void
    {
        $admin = $this->user('Ada Admin', 'admin@example.test', 'administrator');
        $this->user('Eddie Editor', 'editor@example.test', 'editor');

        $published = ['status' => 'published'];

        // Published in both Locales, every Article Field filled.
        $both = $save->handle(Article::class, [...$published, 'title' => 'Bonjour', 'summary' => 'Un article publié dans les deux langues.', 'body' => ['type' => 'doc', 'content' => [['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Un intertitre']]], ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Du texte '], ['type' => 'text', 'text' => 'mis en forme', 'marks' => [['type' => 'bold']]], ['type' => 'text', 'text' => '.']]], ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Un point']]]]]]], ['type' => 'codeBlock', 'attrs' => ['language' => 'php'], 'content' => [['type' => 'text', 'text' => 'echo 1;']]]]], 'author' => 'Ada', 'featured' => true, 'accent' => '#0f766e', 'website' => 'https://example.test', 'contact' => 'ada@example.test', 'pages' => 12, 'rating' => 4.5, 'starts_on' => '2026-12-01', 'starts_at' => '2026-12-01 09:00:00', 'kind' => 'review'], locale: 'fr', actor: $admin);
        $save->handle(Article::class, [...$published, 'title' => 'Hello', 'summary' => 'An article published in both languages.'], $both, 'en', $admin);

        // Published in fr, Draft in en.
        $french = $save->handle(Article::class, [...$published, 'title' => 'Seulement en français', 'summary' => 'La traduction anglaise est un brouillon.', 'author' => 'Ada'], locale: 'fr', actor: $admin);
        $save->handle(Article::class, ['title' => 'French only so far', 'summary' => 'Still being translated.'], $french, 'en', $admin);

        // Scheduled.
        $save->handle(Article::class, [...$published, 'title' => 'À paraître', 'author' => 'Ada', 'published_at' => now()->addMonth()], locale: 'fr', actor: $admin);

        // Draft.
        $save->handle(Article::class, ['title' => 'Brouillon', 'author' => 'Ada'], locale: 'fr', actor: $admin);

        // A blank en Field showing Fallback.
        $fallback = $save->handle(Article::class, [...$published, 'title' => 'Résumé vide', 'summary' => 'Ce résumé est aussi lu en anglais.', 'author' => 'Ada'], locale: 'fr', actor: $admin);
        $save->handle(Article::class, [...$published, 'title' => 'Blank summary'], $fallback, 'en', $admin);

        $about = $save->handle(Page::class, [...$published, 'title' => 'À propos', 'body' => 'Une page à la racine du Site.'], locale: 'fr', actor: $admin);
        $save->handle(Page::class, [...$published, 'title' => 'About', 'body' => 'A page at the root of the Site.'], $about, 'en', $admin);

        // Hierarchical and orderable: two levels under À propos.
        $team = $save->handle(Page::class, [...$published, 'title' => 'Équipe', 'parent' => $about->id, 'body' => 'Une page sous À propos.'], locale: 'fr', actor: $admin);
        $save->handle(Page::class, [...$published, 'title' => 'Histoire', 'parent' => $about->id, 'body' => 'Une autre page sous À propos.'], locale: 'fr', actor: $admin);
        $save->handle(Page::class, ['title' => 'Ada', 'parent' => $team->id], locale: 'fr', actor: $admin);

        // Untranslatable.
        $save->handle(Label::class, [...$published, 'title' => 'Urgent', 'colour' => 'red'], actor: $admin);
        $save->handle(Label::class, [...$published, 'title' => 'Archive', 'colour' => 'grey'], actor: $admin);

        // A Taxonomy: published when saved, one name for every Locale.
        $laravel = $save->handle(Tag::class, ['title' => 'Laravel'], actor: $admin);
        $filament = $save->handle(Tag::class, ['title' => 'Filament', 'parent' => $laravel->id], actor: $admin);

        // Tabs, a section, conditions, a Repeater, a Group, relations.
        $save->handle(Project::class, [...$published, 'title' => 'Refonte', 'client' => 'Acme', 'featured' => true, 'featured_note' => 'Projet phare.', 'kind' => 'review', 'story' => 'Une histoire visible parce que le client est rempli.', 'launched_on' => '2026-06-01', 'links' => [['label' => 'Site', 'url' => 'https://example.test'], ['label' => 'Dépôt', 'url' => 'https://example.test/code']], 'address' => ['street' => 'Rue du Lac 1', 'city' => 'Vevey'], 'related' => [$about->id, $fallback->id, $both->id], 'tags' => [$filament->id, $laravel->id]], locale: 'fr', actor: $admin);
        $save->handle(Project::class, ['title' => 'Esquisse'], locale: 'fr', actor: $admin);

        // A Singleton at the root, one page in the admin.
        $home = $save->handle(Home::class, [...$published, 'title' => 'Accueil', 'intro' => 'Bienvenue sur le Site de démonstration.'], locale: 'fr', actor: $admin);
        $save->handle(Home::class, [...$published, 'title' => 'Home', 'intro' => 'Welcome to the demo Site.'], $home, 'en', $admin);

        // No URL: listed on another page.
        $save->handle(Testimonial::class, [...$published, 'title' => 'Une cliente', 'quote' => 'Un travail soigné.'], locale: 'fr', actor: $admin);

        // Values shown across the Site, always published.
        $footer = $save->handle(Footer::class, ['tagline' => 'Un site de démonstration.', 'contact' => 'ada@example.test'], locale: 'fr', actor: $admin);
        $save->handle(Footer::class, ['tagline' => 'A demo Site.'], $footer, 'en', $admin);
        $save->handle(SiteSettings::class, ['site_name' => 'Kami'], locale: 'fr', actor: $admin);
    }

    private function user(string $name, string $email, string $role): User
    {
        $user = User::forceCreate(['name' => $name, 'email' => $email, 'password' => Hash::make('password')]);
        DB::table('kami_role_user')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->getKey(), 'role' => $role]);

        return $user;
    }
}
