<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal\EntryResource;

use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Psyao\Kami\Admin\Internal\EntryResource;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Fields\Field;
use Psyao\Kami\Content\Internal\Translation;

/**
 * Edits an Entry's Translation in the default Locale; Save keeps its state, Publish and Unpublish change it.
 *
 * @internal
 */
final class EditEntry extends EditRecord
{
    #[\Override]
    protected static string $resource = EntryResource::class;

    /** Set by the Publish and Unpublish actions for the save they start; a plain save keeps the state. */
    private ?string $status = null;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $entry = $this->entry();
        $values = [];

        foreach ([...Translation::ATTRIBUTES, ...array_map(fn (Field $field): string => $field->handle, EntryResource::type()->fields())] as $key) {
            $values[$key] = $entry->getAttribute($key);
        }

        return $values;
    }

    protected function handleRecordUpdate(Model $record, array $data): Entry
    {
        return $this->record = EntryResource::save($data, $this->entry(), $this->status);
    }

    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction(),
            $this->statusAction('publish', Translation::PUBLISHED)->visible(fn (): bool => $this->entry()->status !== Translation::PUBLISHED),
            $this->statusAction('unpublish', Translation::DRAFT)->visible(fn (): bool => $this->entry()->status === Translation::PUBLISHED),
            $this->getCancelFormAction(),
        ];
    }

    private function statusAction(string $name, string $status): Action
    {
        return Action::make($name)
            ->label(__("kami::admin.actions.{$name}"))
            ->authorize(fn (): bool => EntryResource::can('publish', $this->entry()))
            ->action(function () use ($status): void {
                $this->status = $status;
                $this->save();
            });
    }

    private function entry(): Entry
    {
        return $this->record instanceof Entry ? $this->record : throw new LogicException('EditEntry edits an Entry.');
    }
}
