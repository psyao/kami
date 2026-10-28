<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal\EntryResource;

use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Psyao\Kami\Admin\Internal\EntryResource;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Internal\Translation;

/**
 * Writes an Entry's first Translation, in the default Locale, as a Draft or published.
 *
 * @internal
 */
final class CreateEntry extends CreateRecord
{
    #[\Override]
    protected static string $resource = EntryResource::class;

    /** Set by the Publish action for the save it starts; a plain create saves a Draft. */
    private ?string $status = null;

    protected function handleRecordCreation(array $data): Entry
    {
        return EntryResource::save($data, status: $this->status);
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction()->label(__('kami::admin.actions.save_draft')),
            Action::make('publish')
                ->label(__('kami::admin.actions.publish'))
                ->authorize(fn (): bool => EntryResource::can('publish'))
                ->action(function (): void {
                    $this->status = Translation::PUBLISHED;
                    $this->create();
                }),
            $this->getCancelFormAction(),
        ];
    }
}
