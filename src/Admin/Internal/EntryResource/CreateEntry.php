<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal\EntryResource;

use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Livewire\Attributes\Locked;
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

    /** The Parent the Entry starts under: without `reorder` the form saves none, so it is created at the root. */
    #[Locked]
    public ?string $parent = null;

    /** Set by the Publish action for the save it starts; a plain create saves a Draft. */
    private ?string $status = null;

    public function mount(): void
    {
        if (EntryResource::type()->isHierarchical()) {
            $parent = EntryResource::requestedParent();
            $this->parent = EntryResource::can('reorder') ? $parent : null;
        }

        parent::mount();
    }

    public function getBreadcrumbs(): array
    {
        return [...EntryResource::levelBreadcrumbs($this->parent, $this), ...array_slice(parent::getBreadcrumbs(), 1, preserve_keys: true)];
    }

    protected function getCancelFormAction(): Action
    {
        return EntryResource::cancelToLevel(parent::getCancelFormAction(), fn (): ?string => $this->parent);
    }

    protected function handleRecordCreation(array $data): Entry
    {
        return EntryResource::save($data, status: $this->status, state: $this->data ?? []);
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
