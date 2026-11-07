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
        $hidden = self::hiddenTypes($entries, $reader);
        $restricted = self::restricted($entries, $hidden);
        $titles = self::list($entries->whereNotIn('type', $hidden));

        return match (true) {
            $restricted === '' => $titles,
            $titles === '' => $restricted,
            default => __('kami::admin.titles.and_restricted', ['titles' => $titles, 'restricted' => $restricted]),
        };
    }

    /**
     * "2 restricted Entries", or '' when none is.
     *
     * @param  Builder<Entry>  $entries
     * @param  list<string>  $hidden
     */
    public static function restricted(Builder $entries, array $hidden): string
    {
        $count = $hidden === [] ? 0 : $entries->clone()->whereIn('type', $hidden)->count();

        return $count === 0 ? '' : trans_choice('kami::admin.titles.restricted', $count, ['count' => $count]);
    }

    /**
     * The types among these Entries the reader may not view; none with no reader.
     *
     * @param  Builder<Entry>  $entries
     * @return list<string>
     */
    public static function hiddenTypes(Builder $entries, ?Authenticatable $reader): array
    {
        if (! $reader instanceof Authenticatable) {
            return [];
        }

        /** @var list<string> $types */
        $types = $entries->clone()->reorder()->distinct()->pluck('type')->all();

        return array_values(array_diff($types, self::viewableTypes($reader, $types)));
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
