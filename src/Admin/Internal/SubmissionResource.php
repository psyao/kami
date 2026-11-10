<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal;

use Filament\Actions\DeleteBulkAction;
use Filament\Facades\Filament;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource as FilamentResource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Psyao\Kami\Admin\Internal\SubmissionResource\ListSubmissions;
use Psyao\Kami\Admin\Internal\SubmissionResource\ViewSubmission;
use Psyao\Kami\Forms\Actions\DeleteSubmissions;
use Psyao\Kami\Forms\Form;
use Psyao\Kami\Forms\Internal\Delivery;
use Psyao\Kami\Forms\Internal\Forms;
use Psyao\Kami\Forms\Submission;
use Psyao\Kami\Sites\Internal\Site;

/**
 * The Submissions of the current Site, never edited: a tab per Form, a page per Submission that marks it read, and deletion for good under
 * its own Permission (ADR 0014). Registered by `KamiPlugin` only when a Form is declared.
 *
 * @internal
 *
 * @extends FilamentResource<Submission>
 */
final class SubmissionResource extends FilamentResource
{
    #[\Override]
    protected static ?string $model = Submission::class;

    #[\Override]
    protected static ?string $slug = 'submissions';

    public static function getModelLabel(): string
    {
        return __('kami::admin.submissions.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('kami::admin.submissions.plural_label');
    }

    /** As translated: Filament would title-case it. */
    public static function getNavigationLabel(): string
    {
        return self::getPluralModelLabel();
    }

    /** The unread Submissions of the current Site, across its Forms. */
    public static function getNavigationBadge(): ?string
    {
        $unread = self::getEloquentQuery()->whereNull('read_at')->count();

        return $unread > 0 ? (string) $unread : null;
    }

    public static function getNavigationBadgeTooltip(): string
    {
        return __('kami::admin.submissions.unread');
    }

    /** @return Builder<Submission> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('site', Site::current()->handle);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('deliveries'))
            ->columns([
                TextColumn::make('created_at')->label(__('kami::admin.submissions.columns.created_at'))
                    ->dateTime()->timezone(Site::current()->timezone),
                TextColumn::make('summary')->label(__('kami::admin.submissions.columns.summary'))
                    ->state(fn (Submission $record): string => self::summary($record))
                    ->weight(fn (Submission $record): ?string => $record->read_at === null ? 'bold' : null)
                    ->description(fn (Submission $record): ?string => $record->read_at === null ? self::getNavigationBadgeTooltip() : null),
                // From `md` up, as the Media library's: on a phone the rows would scroll sideways.
                TextColumn::make('locale')->label(__('kami::admin.submissions.columns.locale'))->visibleFrom('md')
                    ->formatStateUsing(fn (string $state): string => strtoupper($state)),
                TextColumn::make('delivery')->label(__('kami::admin.submissions.columns.delivery'))
                    ->state(fn (Submission $record): string => self::deliveryState($record->deliveries))
                    ->formatStateUsing(fn (string $state): string => __("kami::admin.submissions.delivery.{$state}"))
                    ->badge()
                    ->color(self::stateColor(...)),
            ])
            ->toolbarActions([
                DeleteBulkAction::make()
                    ->action(function (Collection $records, DeleteBulkAction $action): void {
                        /** @var Collection<int, Submission> $records */
                        resolve(DeleteSubmissions::class)->handle($records, Filament::auth()->user());
                        $action->success();
                    }),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->latest()->orderByDesc('id'))
            ->paginationPageOptions([10, 25, 50]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('kami::admin.submissions.message'))
                ->description(fn (Submission $record): string => __('kami::admin.submissions.received', [
                    'date' => $record->created_at->setTimezone(Site::current()->timezone)->isoFormat('LLL'),
                    'locale' => strtoupper($record->locale),
                ]))
                ->schema(fn (Submission $record): array => array_map(
                    // Escaped, then its line breaks kept: the visitor's text is never HTML.
                    fn (array $field, int $index): TextEntry => TextEntry::make("field_{$index}")->label($field['label'])
                        ->state(nl2br(e($field['value'])))->html(),
                    $values = Forms::values($record, self::declaredForm($record->form)),
                    array_keys($values),
                )),
            Section::make(__('kami::admin.submissions.columns.delivery'))
                ->schema([
                    RepeatableEntry::make('deliveries')->hiddenLabel()
                        ->schema([
                            TextEntry::make('channel')->label(__('kami::admin.submissions.deliveries.channel')),
                            TextEntry::make('state')->label(__('kami::admin.submissions.deliveries.state'))
                                ->state(fn (Delivery $record): string => self::deliveryState(new Collection([$record])))
                                ->formatStateUsing(fn (string $state): string => __("kami::admin.submissions.delivery.{$state}"))
                                ->badge()
                                ->color(self::stateColor(...)),
                            TextEntry::make('attempts')->label(__('kami::admin.submissions.deliveries.attempts')),
                            TextEntry::make('delivered_at')->label(__('kami::admin.submissions.deliveries.delivered_at'))
                                ->dateTime()->timezone(Site::current()->timezone)->placeholder('—'),
                            TextEntry::make('last_error')->label(__('kami::admin.submissions.deliveries.last_error'))
                                ->placeholder('—'),
                        ])
                        ->columns(5),
                ])
                ->visible(fn (Submission $record): bool => $record->deliveries->isNotEmpty()),
        ])->columns(1);
    }

    /** The declared Form of a handle, `null` for one no longer declared: its Submissions stay readable. */
    public static function declaredForm(string $handle): ?Form
    {
        return resolve(Forms::class)->find($handle);
    }

    /** The Form's label, or the handle of one no longer declared. */
    public static function formLabel(string $handle): string
    {
        return self::declaredForm($handle)?->label() ?? $handle;
    }

    /** The Form's `summary()`, or the first text value by key for a Form no longer declared. */
    private static function summary(Submission $submission): string
    {
        $form = self::declaredForm($submission->form);

        if ($form instanceof Form) {
            return $form->summary($submission);
        }

        $text = collect(Forms::values($submission, null))->first(fn (array $field): bool => trim($field['value']) !== '');

        return Str::limit(Str::squish($text['value'] ?? ''), 100);
    }

    /**
     * Across its channels: `failed` once one is abandoned, `delivered` once all are, `retrying` once one has failed, otherwise `pending`; `none` without a channel.
     *
     * @param  Collection<int, Delivery>  $deliveries
     */
    private static function deliveryState(Collection $deliveries): string
    {
        return match (true) {
            $deliveries->isEmpty() => 'none',
            $deliveries->contains(fn (Delivery $delivery): bool => $delivery->isAbandoned()) => 'failed',
            $deliveries->every(fn (Delivery $delivery): bool => $delivery->delivered_at !== null) => 'delivered',
            $deliveries->contains(fn (Delivery $delivery): bool => $delivery->delivered_at === null && $delivery->last_error !== null) => 'retrying',
            default => 'pending',
        };
    }

    private static function stateColor(string $state): string
    {
        return match ($state) {
            'failed' => 'danger',
            'retrying' => 'warning',
            default => 'gray',
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubmissions::route('/'),
            'view' => ViewSubmission::route('/{record}'),
        ];
    }
}
