<?php

declare(strict_types=1);

namespace Workbench\App\Content;

use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Fields\Boolean;
use Psyao\Kami\Content\Fields\Date;
use Psyao\Kami\Content\Fields\Entries;
use Psyao\Kami\Content\Fields\Group;
use Psyao\Kami\Content\Fields\Repeater;
use Psyao\Kami\Content\Fields\Select;
use Psyao\Kami\Content\Fields\Terms;
use Psyao\Kami\Content\Fields\Text;
use Psyao\Kami\Content\Fields\Textarea;
use Psyao\Kami\Content\Fields\Url;
use Psyao\Kami\Content\Section;
use Psyao\Kami\Content\Tab;

/** A form arranged in tabs and a section, with Fields shown only when they apply, a list of rows and a set of named values. */
final class Project extends ContentType
{
    #[\Override]
    protected bool $orderable = true;

    public function fields(): array
    {
        return [
            Tab::make('details', [
                Text::make('client')->shared(),
                Section::make('highlight', [
                    Boolean::make('featured'),
                    Text::make('featured_note')->required()->visibleWhen('featured', true),
                ]),
                Select::make('kind')->options(['news', 'review', 'essay']),
                Text::make('source')->shared()->default('https://example.test/press')->rules(['url'])->visibleWhen('kind', ['review', 'essay']),
            ]),
            Tab::make('story', [
                Textarea::make('story')->visibleWhenFilled('client'),
            ]),
            Date::make('launched_on'),
            Repeater::make('links', [
                Text::make('label'),
                Url::make('url')->required(),
            ]),
            Group::make('address', [
                Text::make('street'),
                Text::make('city'),
            ])->default(['city' => 'Vevey']),
            Entries::make('related')->of(Article::class, Page::class),
            Terms::make('tags')->of(Tag::class),
        ];
    }
}
