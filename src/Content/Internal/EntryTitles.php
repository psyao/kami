<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
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
     * The first titles, then how many more: "A, B (in the Trash) and 3 more".
     *
     * @param  Builder<Entry>  $entries
     */
    public static function list(Builder $entries): string
    {
        $count = $entries->count();
        $titles = $entries->limit(self::SHOWN)->get()->map(self::marked(...))->implode(', ');

        return $count > self::SHOWN ? __('kami::admin.titles.more', ['titles' => $titles, 'count' => $count - self::SHOWN]) : $titles;
    }

    /**
     * As `list()`, for a reader: Entries of types they may not view are counted, with no title (ADR 0014). With no reader, the CLI or an import, every Entry is titled.
     *
     * @param  Builder<Entry>  $entries
     */
    public static function listFor(Builder $entries, ?Authenticatable $reader): string
    {
        $restricted = self::withoutRestricted($entries, $reader);
        $titles = self::list($entries);

        return match (true) {
            $restricted === '' => $titles,
            $titles === '' => $restricted,
            default => __('kami::admin.titles.and_restricted', ['titles' => $titles, 'restricted' => $restricted]),
        };
    }

    /**
     * Narrows the query to the types the reader may view, and counts what it left out: "2 restricted Entries", or '' when none is.
     * With no reader, the CLI or an import, it leaves the query whole.
     *
     * @param  Builder<Entry>  $entries  Narrowed in place.
     */
    public static function withoutRestricted(Builder $entries, ?Authenticatable $reader): string
    {
        if (! $reader instanceof Authenticatable) {
            return '';
        }

        /** @var list<string> $types */
        $types = $entries->clone()->reorder()->distinct()->pluck('type')->all();
        $hidden = array_values(array_diff($types, self::viewableTypes($reader, $types)));
        if ($hidden === []) {
            return '';
        }

        $count = $entries->clone()->whereIn('type', $hidden)->count();
        $entries->whereNotIn('type', $hidden);

        return trans_choice('kami::admin.titles.restricted', $count, ['count' => $count]);
    }

    /**
     * The types among these whose Entries the User may view, asked through the Policy: only those are offered and titled to them (ADR 0014).
     *
     * @param  list<string>  $types
     * @return list<string>
     */
    public static function viewableTypes(?Authenticatable $user, array $types): array
    {
        $gate = Gate::forUser($user);

        return array_values(array_filter($types, fn (string $type): bool => $gate->allows('viewAny', [Entry::class, $type])));
    }

    /** Its title, marked when it is in the Trash. */
    public static function marked(Entry $entry): string
    {
        return $entry->trashed() ? __('kami::admin.titles.trashed', ['title' => self::of($entry)]) : self::of($entry);
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
