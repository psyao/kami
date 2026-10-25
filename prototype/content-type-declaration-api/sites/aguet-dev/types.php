<?php

// PROTOTYPE. aguet.dev's Content Types, as a site would write them in app/Content/.
// One file here for reading; a real site has one class per file.

namespace AguetDev\Content; // App\Content in the real site

use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Fields\Boolean;
use Psyao\Kami\Content\Fields\Email;
use Psyao\Kami\Content\Fields\Entries;
use Psyao\Kami\Content\Fields\RichText;
use Psyao\Kami\Content\Fields\Text;
use Psyao\Kami\Content\Fields\Textarea;
use Psyao\Kami\Content\Fields\Url;
use Psyao\Kami\Content\Section;
use Psyao\Kami\Content\Singleton;
use Psyao\Kami\Content\Tab;
use Psyao\Kami\Content\Taxonomy;

// Labels, help texts and tab titles come from lang/{en,fr}/kami-types.php by convention:
// 'home' => ['singular' => 'Page d\'accueil', 'tabs' => ['hero' => 'En-tête', ...], 'fields' => [...]]

final class Home extends Singleton
{
    protected ?string $prefix = '';

    public function fields(): array
    {
        return [
            Tab::make('hero', [
                Textarea::make('hero_title')->inlineMarkdown()->required(),
                Text::make('hero_subtitle')->inlineMarkdown(),
                Section::make('facts', [
                    Text::make('hero_role'),
                    Text::make('hero_location'),
                    Text::make('hero_exp'),
                    Text::make('hero_focus'),
                ]),
            ]),
            Tab::make('about', [
                RichText::make('about_body'),
            ]),
            Tab::make('contact', [
                Text::make('contact_lead')->inlineMarkdown(),
                Email::make('contact_email')->required(),
                // A reusable fieldset is plain PHP: a function returning Fields.
                ...SocialLink::fields('contact_linkedin'),
                ...SocialLink::fields('contact_github'),
            ]),
        ];
    }
}

/** Reusable fieldset: no Kami concept, a static method. The prefix keeps column names unique. */
final class SocialLink
{
    public static function fields(string $prefix): array
    {
        return [
            Url::make("{$prefix}"),
            Text::make("{$prefix}_label")->shared()->visibleWhenFilled($prefix),
        ];
    }
}

final class Project extends ContentType
{
    protected bool $orderable = true;

    protected ?string $model = \App\Models\Project::class; // adds host()

    public function fields(): array
    {
        return [
            Text::make('client'),
            Text::make('role'),
            Textarea::make('summary')->inlineMarkdown(),
            Url::make('url'),
            Boolean::make('featured')->filterable(),
            Entries::make('tags')->of(Tag::class)->multiple()->creatable(),
        ];
    }
}

final class SkillGroup extends ContentType
{
    protected bool $orderable = true;

    public function fields(): array
    {
        return [
            Entries::make('tags')->of(Tag::class)->multiple()->creatable(),
            Text::make('text')->help('Replaces the tags when set (e.g. Languages).'),
            Boolean::make('focus'),
            Text::make('note')->visibleWhen('focus', true),
        ];
    }
}

/** Flat, no Archive (prefix null). Whether a Term's title is translatable is for "Multilingual content and fallback". */
final class Tag extends Taxonomy {}
