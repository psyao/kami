<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal\SubmissionResource;

use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Psyao\Kami\Admin\Internal\SubmissionResource;
use Psyao\Kami\Forms\Internal\Forms;
use Psyao\Kami\Forms\Submission;

/** @internal */
final class ListSubmissions extends ListRecords
{
    #[\Override]
    protected static string $resource = SubmissionResource::class;

    /**
     * One per declared Form, then one per handle no Form declares any more whose Submissions remain (`kami:doctor` reports them), so they can be read and deleted.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        /** @var list<string> $stored */
        $stored = SubmissionResource::getEloquentQuery()->distinct()->orderBy('form')->pluck('form')->all();
        $handles = array_values(array_unique([...array_keys(resolve(Forms::class)->all()), ...$stored]));

        return array_combine($handles, array_map(
            fn (string $handle): Tab => Tab::make(SubmissionResource::formLabel($handle))
                ->modifyQueryUsing(fn (Builder $query): Builder => $this->whereForm($query, $handle)),
            $handles,
        ));
    }

    /**
     * @param  Builder<Submission>  $query
     * @return Builder<Submission>
     */
    private function whereForm(Builder $query, string $handle): Builder
    {
        return $query->where('form', $handle);
    }
}
