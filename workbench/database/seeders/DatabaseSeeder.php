<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Psyao\Kami\Audit\Actions\RecordAuditEvent;
use Psyao\Kami\Content\Actions\DeleteEntry;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Actions\TrashEntry;
use Psyao\Kami\Content\Fields\RichText;
use Psyao\Kami\Content\RichText\Document;
use Psyao\Kami\Content\SiteSettings;
use Psyao\Kami\Media\Actions\SaveMedia;
use Psyao\Kami\Users\Actions\AssignRoles;
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
    public function run(SaveEntry $save, TrashEntry $trash, DeleteEntry $delete, SaveMedia $media): void
    {
        $admin = $this->user('Ada Admin', 'admin@example.test', 'administrator');
        // MFA is required of a manager: add this secret to an authenticator app to sign in as Ada. Eddie may set it up from his profile.
        $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->user('Eddie Editor', 'editor@example.test', 'editor');

        $published = ['status' => 'published'];

        // Published in both Locales, every Article Field filled, and a Meta title and description in fr.
        $both = $save->handle(Article::class, [...$published, 'title' => 'Bonjour', 'summary' => 'Un article publié dans les deux langues.', 'body' => ['type' => 'doc', 'content' => [['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Un intertitre']]], ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Du texte '], ['type' => 'text', 'text' => 'mis en forme', 'marks' => [['type' => 'bold']]], ['type' => 'text', 'text' => '.']]], ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Un point']]]]]]], ['type' => 'codeBlock', 'attrs' => ['language' => 'php'], 'content' => [['type' => 'text', 'text' => 'echo 1;']]]]], 'author' => 'Ada', 'featured' => true, 'accent' => '#0f766e', 'website' => 'https://example.test', 'contact' => 'ada@example.test', 'pages' => 12, 'rating' => 4.5, 'starts_on' => '2026-12-01', 'starts_at' => '2026-12-01 09:00:00', 'kind' => 'review', 'meta_title' => 'Bonjour, en deux langues', 'meta_description' => 'La description que les moteurs de recherche affichent, écrite dans la section SEO.'], locale: 'fr', actor: $admin);
        $save->handle(Article::class, [...$published, 'title' => 'Hello', 'summary' => 'An article published in both languages.'], $both, 'en', $admin);

        // Published in fr, Draft in en, Hidden from search engines in en.
        $french = $save->handle(Article::class, [...$published, 'title' => 'Seulement en français', 'summary' => 'La traduction anglaise est un brouillon.', 'author' => 'Ada'], locale: 'fr', actor: $admin);
        $save->handle(Article::class, ['title' => 'French only so far', 'summary' => 'Still being translated.', 'noindex' => true], $french, 'en', $admin);

        // Scheduled.
        $save->handle(Article::class, [...$published, 'title' => 'À paraître', 'author' => 'Ada', 'published_at' => now()->addMonth()], locale: 'fr', actor: $admin);

        // Draft, its body imported from HTML with a skipped heading level: saving it shows a reminder.
        $save->handle(Article::class, ['title' => 'Brouillon', 'author' => 'Ada', 'body' => Document::fromHtml('<h3>Un intertitre trop bas</h3><p>Enregistrez : un rappel signale le niveau sauté.</p>', RichText::make('body'))], locale: 'fr', actor: $admin);

        // A blank en Field showing Fallback.
        $fallback = $save->handle(Article::class, [...$published, 'title' => 'Résumé vide', 'summary' => 'Ce résumé est aussi lu en anglais.', 'author' => 'Ada'], locale: 'fr', actor: $admin);
        $save->handle(Article::class, [...$published, 'title' => 'Blank summary'], $fallback, 'en', $admin);

        // In the Trash, found through the table's filter; its address stays reserved.
        $trash->handle($save->handle(Article::class, [...$published, 'title' => 'Dans la corbeille', 'author' => 'Ada'], locale: 'fr', actor: $admin), $admin);

        // Deleted for good: its former address answers 301 to Bonjour.
        $deleted = $save->handle(Article::class, [...$published, 'title' => 'Ancien article', 'author' => 'Ada'], locale: 'fr', actor: $admin);
        $trash->handle($deleted, $admin);
        $delete->handle($deleted, $both, $admin);

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

        // A Singleton at the root, one page in the admin.
        $home = $save->handle(Home::class, [...$published, 'title' => 'Accueil', 'intro' => 'Bienvenue sur le Site de démonstration.'], locale: 'fr', actor: $admin);
        $save->handle(Home::class, [...$published, 'title' => 'Home', 'intro' => 'Welcome to the demo Site.'], $home, 'en', $admin);

        // No URL: listed on another page.
        $save->handle(Testimonial::class, [...$published, 'title' => 'Une cliente', 'quote' => 'Un travail soigné.'], locale: 'fr', actor: $admin);

        // Values shown across the Site, always published.
        $footer = $save->handle(Footer::class, ['tagline' => 'Un site de démonstration.', 'contact' => 'ada@example.test'], locale: 'fr', actor: $admin);
        $save->handle(Footer::class, ['tagline' => 'A demo Site.'], $footer, 'en', $admin);
        $settings = $save->handle(SiteSettings::class, ['site_name' => 'Kami', 'default_description' => 'Le Site de démonstration de Kami.'], locale: 'fr', actor: $admin);
        $save->handle(SiteSettings::class, ['default_description' => 'The Kami demo Site.'], $settings, 'en', $admin);

        // The Media library: a photo described in both Locales, an image missing its alt text, a decorative one, an SVG served as its own file, a document, which needs none.
        $fixtures = dirname(__DIR__, 3) . '/tests/Fixtures/media';
        $photo = $media->handle("{$fixtures}/Holiday Photo.JPG", ['alt' => 'Une photo de vacances', 'caption' => 'Vacances au bord du lac', 'credit' => 'Ada'], locale: 'fr', actor: $admin);
        $media->handle(null, ['alt' => 'A holiday photo', 'caption' => 'Holidays by the lake'], $photo, 'en', $admin);
        $media->handle("{$fixtures}/rotated-gps.jpg", ['decorative' => true], actor: $admin);
        $pixel = $media->handle("{$fixtures}/pixel.png", actor: $admin);
        $media->handle("{$fixtures}/logo.svg", ['alt' => 'Un cercle blanc sur fond vert'], locale: 'fr', actor: $admin);
        $brochure = $media->handle("{$fixtures}/brochure.pdf", actor: $admin);
        // Every page shared without an image of its own shows the photo; a Project shows its cover.
        $save->handle(SiteSettings::class, ['default_social_image' => $photo->id], $settings, 'fr', $admin);

        // Tabs, a section, conditions, a Repeater, a Group, relations, and Media Fields picking from the library: a cover, a gallery in pick order.
        $save->handle(Project::class, [...$published, 'title' => 'Refonte', 'client' => 'Acme', 'featured' => true, 'featured_note' => 'Projet phare.', 'kind' => 'review', 'story' => 'Une histoire visible parce que le client est rempli.', 'launched_on' => '2026-06-01', 'links' => [['label' => 'Site', 'url' => 'https://example.test'], ['label' => 'Dépôt', 'url' => 'https://example.test/code']], 'address' => ['street' => 'Rue du Lac 1', 'city' => 'Vevey'], 'related' => [$about->id, $fallback->id, $both->id], 'tags' => [$filament->id, $laravel->id], 'cover' => $photo->id, 'gallery' => [$pixel->id, $photo->id, $brochure->id]], locale: 'fr', actor: $admin);
        $save->handle(Project::class, ['title' => 'Esquisse'], locale: 'fr', actor: $admin);

        // The Audit log already holds every write above. Two more rows worth looking at: a wrong password on Ada's account,
        // and an Entry published by a User deleted since, shown as "Deleted User (id)".
        resolve(RecordAuditEvent::class)->handle('kami.auth.sign-in-failed', $admin);
        $former = $this->user('Fiona Former', 'former@example.test', 'editor');
        $save->handle(Article::class, [...$published, 'title' => 'Écrit par une ancienne', 'author' => 'Fiona'], locale: 'fr', actor: $former);
        $former->delete();
    }

    private function user(string $name, string $email, string $role): User
    {
        $user = User::forceCreate(['name' => $name, 'email' => $email, 'password' => Hash::make('password')]);
        resolve(AssignRoles::class)->handle($user, [$role]);

        return $user;
    }
}
