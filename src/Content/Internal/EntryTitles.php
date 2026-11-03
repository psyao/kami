<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

use Illuminate\Database\Eloquent\Builder;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Sites\Internal\Site;

/**
 * Entries named for a person in a sentence: what a refusal or a warning lists.
 *
 * @internal
 */
final class EntryTitles
{
    private const int SHOWN = 10;

    /**
     * The first titles, then how many more: "A, B and 3 more".
     *
     * @param  Builder<Entry>  $entries
     */
    public static function list(Builder $entries): string
    {
        $count = $entries->count();
        $titles = $entries->limit(self::SHOWN)->get()->map(self::of(...))->implode(', ');

        return $count > self::SHOWN ? __('kami::admin.titles.more', ['titles' => $titles, 'count' => $count - self::SHOWN]) : $titles;
    }

    /** Its title in the current Locale, else the default one's, else any. */
    public static function of(Entry $entry): string
    {
        $titles = $entry->translations->whereNotNull('title');

        return $titles->firstWhere('locale', Site::current()->currentLocale())->title
            ?? $titles->firstWhere('locale', Site::current()->defaultLocale())->title
            ?? $titles->first()->title
            ?? __('kami::admin.fields.untitled');
    }
}
