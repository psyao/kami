<?php

declare(strict_types=1);

namespace Workbench\App\Content;

use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Fields\Boolean;
use Psyao\Kami\Content\Fields\Date;
use Psyao\Kami\Content\Fields\Select;
use Psyao\Kami\Content\Fields\Text;
use Psyao\Kami\Content\Fields\Textarea;
use Psyao\Kami\Content\Section;
use Psyao\Kami\Content\Tab;

/** A form arranged in tabs and a section, with Fields shown only when they apply. */
final class Project extends ContentType
{
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
        ];
    }
}
