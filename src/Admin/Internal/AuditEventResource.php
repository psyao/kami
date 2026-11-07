<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal;

use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource as FilamentResource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Psyao\Kami\Admin\Internal\AuditEventResource\ListAuditEvents;
use Psyao\Kami\Audit\Internal\AuditEvent;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Internal\EntryTitles;
use Psyao\Kami\Media\Media;
use Psyao\Kami\Sites\Internal\Site;

/**
 * The Audit log, read only: no page or action creates, edits or deletes an event; only `kami:audit:prune` removes them (ADR 0022).
 *
 * @internal
 *
 * @extends FilamentResource<AuditEvent>
 */
final class AuditEventResource extends FilamentResource
{
    #[\Override]
    protected static ?string $model = AuditEvent::class;

    public static function getModelLabel(): string
    {
        return __('kami::admin.audit.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('kami::admin.audit.plural_label');
    }

    /** As translated: Filament would title-case it. */
    public static function getNavigationLabel(): string
    {
        return self::getPluralModelLabel();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_at')->label(__('kami::admin.audit.columns.occurred_at'))
                    ->dateTime()->timezone(Site::current()->timezone),
                TextColumn::make('actor')->label(__('kami::admin.audit.columns.actor'))
                    ->state(fn (AuditEvent $record, ListAuditEvents $livewire): string => self::actor($record, $livewire->names())),
                TextColumn::make('event')->label(__('kami::admin.audit.columns.event'))
                    ->formatStateUsing(fn (string $state): string => self::eventLabel($state)),
                TextColumn::make('subject')->label(__('kami::admin.audit.columns.subject'))
                    ->state(fn (AuditEvent $record, ListAuditEvents $livewire): ?string => $livewire->subject($record)[0] ?? null)
                    ->url(fn (AuditEvent $record, ListAuditEvents $livewire): ?string => $livewire->subject($record)[1] ?? null),
                // From `md` up, as the Media library's: on a phone the rows would scroll sideways.
                TextColumn::make('site')->label(__('kami::admin.audit.columns.site'))->visibleFrom('md'),
                TextColumn::make('ip')->label(__('kami::admin.audit.columns.ip'))->visibleFrom('md'),
                TextColumn::make('context')->label(__('kami::admin.audit.columns.context'))->visibleFrom('md')->wrap()
                    ->state(fn (AuditEvent $record): string => self::context($record)),
            ])
            ->filters([
                // A User acting or acted upon: a failed sign-in or a Role granted by someone else has the User as its subject only.
                SelectFilter::make('user')->label(__('kami::admin.audit.filters.user'))
                    ->options(fn (): array => self::userOptions())
                    ->searchable()
                    ->query(self::whereUser(...)),
                SelectFilter::make('event')->label(__('kami::admin.audit.filters.event'))
                    ->options(fn (): array => self::eventOptions())
                    ->searchable(),
                // Days of the Site's time zone, as the dates are shown.
                Filter::make('occurred_at')
                    ->schema([
                        DatePicker::make('from')->label(__('kami::admin.audit.filters.from')),
                        DatePicker::make('until')->label(__('kami::admin.audit.filters.until')),
                    ])
                    ->query(self::whereOccurred(...)),
            ])
            ->defaultSort(self::newestFirst(...))
            ->paginationPageOptions([10, 25, 50]);
    }

    /**
     * As actor or as subject.
     *
     * @param  Builder<AuditEvent>  $query
     * @param  array{value?: string|null}  $data
     */
    private static function whereUser(Builder $query, array $data): void
    {
        $user = $data['value'] ?? null;

        if (filled($user)) {
            $query->where(fn (Builder $query) => $query->where('user_id', $user)
                ->orWhere(fn (Builder $query) => $query->where('subject_type', 'user')->where('subject_id', $user)));
        }
    }

    /**
     * @param  Builder<AuditEvent>  $query
     * @param  array{from?: string|null, until?: string|null}  $data
     */
    private static function whereOccurred(Builder $query, array $data): void
    {
        $timezone = Site::current()->timezone;

        if (is_string($data['from'] ?? null)) {
            $query->where('occurred_at', '>=', Date::parse($data['from'], $timezone)->startOfDay()->utc());
        }

        if (is_string($data['until'] ?? null)) {
            $query->where('occurred_at', '<', Date::parse($data['until'], $timezone)->addDay()->startOfDay()->utc());
        }
    }

    /**
     * Ties broken by id, a ULID: rows of one second keep their order.
     *
     * @param  Builder<AuditEvent>  $query
     * @return Builder<AuditEvent>
     */
    private static function newestFirst(Builder $query): Builder
    {
        return $query->latest('occurred_at')->orderByDesc('id');
    }

    /** Kami's own events translated, a site's own shown by name. */
    public static function eventLabel(string $event): string
    {
        $key = 'kami::admin.audit.events.' . Str::after($event, 'kami.');

        return str_starts_with($event, 'kami.') && Lang::has($key) && is_string($label = __($key)) ? $label : $event;
    }

    /**
     * The subjects of these events the reader may see, as `[label, url]` by `subjectKey()`: an Entry or a Media item the User may open is linked, a User named.
     * One gone or out of reach is left out, for `ListAuditEvents::subject()` to show by its id.
     * One query per subject type, so a page of events costs the same whatever it holds.
     *
     * @param  iterable<AuditEvent>  $events
     * @param  array<array-key, string>  $names  The Users' names, by key.
     * @return array<string, array{string, ?string}>
     */
    public static function describeSubjects(iterable $events, array $names): array
    {
        $ids = [];

        foreach ($events as $event) {
            if ($event->subject_type !== null && $event->subject_id !== null) {
                $ids[$event->subject_type][] = $event->subject_id;
            }
        }

        $described = [];
        $readable = EntryResource::accessibleTypes();

        foreach (Entry::query()->withUnpublished()->withTrashed()->whereKey($ids['entry'] ?? [])->with('translations')->get() as $entry) {
            if (in_array($entry->type, $readable, true)) {
                // One in the Trash has no edit page to link to.
                $described["entry:{$entry->id}"] = [self::subjectLabel('entry', EntryTitles::marked($entry)), $entry->trashed() ? null : EntryResource::withConfiguration($entry->type, fn (): string => EntryResource::editUrl($entry, null))];
            }
        }

        if (MediaResource::canViewAny()) {
            foreach (Media::query()->without('translations')->whereKey($ids['media'] ?? [])->get() as $media) {
                $described["media:{$media->id}"] = [self::subjectLabel('media', $media->name), MediaResource::canEdit($media) ? MediaResource::getUrl('edit', ['record' => $media]) : null];
            }
        }

        foreach ($ids['user'] ?? [] as $id) {
            $described["user:{$id}"] = [self::subjectLabel('user', self::userName($names, $id)), null];
        }

        return $described;
    }

    public static function subjectKey(AuditEvent $event): ?string
    {
        return $event->subject_type !== null && $event->subject_id !== null ? "{$event->subject_type}:{$event->subject_id}" : null;
    }

    /**
     * Its name, or "Deleted User (id)" once gone.
     *
     * @param  array<array-key, string>  $names
     */
    public static function userName(array $names, int | string | null $key): string
    {
        return $names[(string) $key] ?? __('kami::admin.audit.deleted_user', ['id' => $key]);
    }

    /** @param  array<array-key, string>  $names */
    private static function actor(AuditEvent $event, array $names): string
    {
        return $event->actor === 'user' ? self::userName($names, $event->user_id) : __("kami::admin.audit.actors.{$event->actor}");
    }

    public static function subjectLabel(string $type, string $name): string
    {
        $key = "kami::admin.audit.subjects.{$type}";

        return (Lang::has($key) ? __($key) : $type) . ': ' . $name;
    }

    private static function context(AuditEvent $event): string
    {
        // Sorted, as MySQL's JSON reorders keys.
        return collect($event->context)
            ->sortKeys()
            ->map(fn (bool | float | int | string | null $value, string $key): string => $key . ': ' . (is_string($value) ? $value : (string) json_encode($value)))
            ->implode(', ');
    }

    /** @return array<string, string> */
    private static function eventOptions(): array
    {
        /** @var list<string> $events */
        $events = AuditEvent::query()->distinct()->orderBy('event')->pluck('event')->all();

        return array_combine($events, array_map(self::eventLabel(...), $events));
    }

    /**
     * Every User the log names, as actor or subject, deleted ones included: an incident may be about a User gone since.
     *
     * @return array<string, string>
     */
    private static function userOptions(): array
    {
        /** @var list<int|string> $found */
        $found = [
            ...AuditEvent::query()->whereNotNull('user_id')->distinct()->pluck('user_id')->all(),
            ...AuditEvent::query()->where('subject_type', 'user')->distinct()->pluck('subject_id')->all(),
        ];
        $keys = array_values(array_unique(array_map(strval(...), $found)));
        $names = EntryResource::userNames($keys);

        return collect($keys)
            ->mapWithKeys(fn (string $key): array => [$key => self::userName($names, $key)])
            ->sort()
            ->all();
    }

    public static function getPages(): array
    {
        return ['index' => ListAuditEvents::route('/')];
    }
}
