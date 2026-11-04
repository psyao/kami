<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal\Widgets;

use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Psyao\Kami\Sites\Internal\Site;
use Psyao\Kami\Support\Internal\Doctor\DoctorCommand;
use Psyao\Kami\Support\Internal\Doctor\Severity;

/**
 * The problems of the last `kami:doctor` run, read from the file it writes: no check runs on a web request.
 * Messages stay in English, as the command prints them; M5 adds Media without Alt text, M8 the Tick, tasks and queue.
 *
 * @internal
 */
final class NeedsAttention extends TableWidget
{
    #[\Override]
    protected static ?int $sort = 12;

    #[\Override]
    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Gate::forUser(Filament::auth()->user())->check('kami.health.view');
    }

    public function table(Table $table): Table
    {
        $result = self::result();
        $checkedAt = $result === null ? null : (string) __('kami::admin.widgets.attention.checked_at', [
            'date' => $result['checked_at']->setTimezone(Site::current()->timezone)->translatedFormat($table->getDefaultDateTimeDisplayFormat()),
        ]);

        return $table
            ->heading(__('kami::admin.widgets.attention.heading'))
            ->description($checkedAt)
            ->records(fn (): array => $result['problems'] ?? [])
            ->paginated(false)
            ->emptyStateHeading(__($result === null ? 'kami::admin.widgets.attention.never' : 'kami::admin.widgets.attention.none'))
            ->emptyStateDescription(__('kami::admin.widgets.attention.run', ['command' => 'php artisan kami:doctor']))
            ->columns([
                TextColumn::make('severity')->label(__('kami::admin.widgets.attention.severity'))->badge()
                    // `result()` keeps Kami's severities only; warning and info are Filament colours too.
                    ->formatStateUsing(fn (string $state): string => __("kami::admin.widgets.attention.{$state}"))
                    ->color(fn (string $state): string => $state === Severity::Error->value ? 'danger' : $state),
                TextColumn::make('message')->label(__('kami::admin.widgets.attention.problem'))->wrap()
                    ->description(
                        fn (array $record): string => (string) __('kami::admin.widgets.attention.fix', ['fix' => Arr::string($record, 'fix')])
                    ),
            ]);
    }

    /**
     * The last result as `kami:doctor` wrote it, or null while none is readable; a problem of another shape is left out.
     *
     * @return array{checked_at: CarbonImmutable, problems: list<array{severity: string, message: string, fix: string}>}|null
     */
    private static function result(): ?array
    {
        return rescue(function (): ?array {
            $result = File::exists($path = DoctorCommand::resultPath()) ? File::json($path, JSON_THROW_ON_ERROR) : null;

            if (! is_array($result) || ! is_string($result['checked_at'] ?? null) || ! is_array($result['problems'] ?? null)) {
                return null;
            }

            $problems = array_values(array_filter($result['problems'], fn (mixed $problem): bool => is_array($problem)
                && is_string($problem['severity'] ?? null) && Severity::tryFrom($problem['severity']) instanceof Severity
                && is_string($problem['message'] ?? null) && is_string($problem['fix'] ?? null)));

            /** @var list<array{severity: string, message: string, fix: string}> $problems */
            return ['checked_at' => CarbonImmutable::parse($result['checked_at']), 'problems' => $problems];
        }, report: false);
    }
}
