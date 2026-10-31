<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal\EntryResource;

use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use LogicException;
use Psyao\Kami\Admin\Internal\EntryResource;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Fields\Field;
use Psyao\Kami\Content\GlobalSet;
use Psyao\Kami\Content\Internal\References;
use Psyao\Kami\Content\Internal\Translation;
use Psyao\Kami\Content\Singleton;
use Psyao\Kami\Sites\Internal\Site;

/**
 * Edits an Entry's Translation in one Locale, the URL's; Save keeps its state, Publish and Unpublish change it.
 *
 * Saving a Locale with no Translation yet creates it. An untranslatable type has its `'*'` Translation only, at the default Locale's URL.
 *
 * @internal
 */
final class EditEntry extends EditRecord
{
    #[\Override]
    protected static string $resource = EntryResource::class;

    /** The Locale edited: the record is read in it, so the policy checks its Translation (ADR 0019). */
    #[Locked]
    public string $locale;

    /** Set by the Publish and Unpublish actions for the save they start; a plain save keeps the state. */
    private ?string $status = null;

    /**
     * A Singleton's URL names no record: its one Entry, which `kami:install` creates; 404 where it has not run yet.
     */
    public function mount(int | string | null $record = null, ?string $locale = null): void
    {
        $default = Site::current()->defaultLocale();

        // The default Locale has no segment of its own, as on the front.
        abort_unless($locale === null || ($locale !== $default && in_array($locale, EntryResource::locales(), true)), 404);
        $this->locale = $locale ?? $default;

        parent::mount($record ?? EntryResource::getEloquentQuery()->where('site', Site::current()->handle)->firstOrFail()->id);
    }

    /** Read in the page's Locale on every call: Livewire reloads the record on each request, which would read it in the default one. */
    public function getRecord(): Entry
    {
        $entry = parent::getRecord();

        if (! $entry instanceof Entry) {
            throw new LogicException('EditEntry edits an Entry.');
        }
        $entry->readIn($this->locale);

        return $entry;
    }

    /** A Singleton has no list to lead back to. */
    public function getBreadcrumbs(): array
    {
        return EntryResource::type() instanceof Singleton ? [] : parent::getBreadcrumbs();
    }

    public function getSubheading(): ?string
    {
        return EntryResource::locales() === [] ? null : (string) __('kami::admin.locale', ['locale' => $this->locale]);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $entry = $this->getRecord();
        $values = [];
        $relations = EntryResource::type()->relationFields();

        if ($relations !== []) {
            References::load([$entry], array_column($relations, 'handle'));
        }

        foreach ([...Translation::ATTRIBUTES, ...array_map(fn (Field $field): string => $field->handle, EntryResource::type()->flatFields())] as $key) {
            // A value fallen back from the default Locale is not this Locale's: saved, it would become its own.
            $value = $entry->fallbackLocale($key) === null ? $entry->getAttribute($key) : null;
            $values[$key] = $value instanceof Collection ? $value->modelKeys() : $value;
        }

        return $values;
    }

    protected function handleRecordUpdate(Model $record, array $data): Entry
    {
        return $this->record = EntryResource::save($data, $this->getRecord(), $this->status, $this->locale, $this->data ?? []);
    }

    protected function getHeaderActions(): array
    {
        $default = Site::current()->defaultLocale();

        return [
            // ponytail: one link per other Locale; M4's Locale switcher with status badges replaces them.
            ...array_map(fn (string $locale): Action => Action::make("locale_{$locale}")
                ->label(__($this->getRecord()->translations->contains('locale', $locale) ? 'kami::admin.actions.edit_locale' : 'kami::admin.actions.add_locale', ['locale' => $locale]))
                ->color('gray')
                ->url(EntryResource::editUrl($this->getRecord(), $locale === $default ? null : $locale)), array_values(array_diff(EntryResource::locales(), [$this->locale]))),
            Action::make('view')
                ->label(__('kami::admin.actions.view'))
                ->color('gray')
                ->url(fn (): ?string => $this->getRecord()->url(), shouldOpenInNewTab: true)
                ->visible(fn (): bool => $this->getRecord()->url() !== null),
        ];
    }

    protected function getFormActions(): array
    {
        if (EntryResource::type() instanceof GlobalSet) {
            return [$this->getSaveFormAction(), $this->getCancelFormAction()];
        }

        return [
            $this->getSaveFormAction(),
            $this->statusAction('publish', Translation::PUBLISHED)->visible(fn (): bool => $this->getRecord()->status !== Translation::PUBLISHED),
            $this->statusAction('unpublish', Translation::DRAFT)->visible(fn (): bool => $this->getRecord()->status === Translation::PUBLISHED),
            $this->getCancelFormAction(),
        ];
    }

    private function statusAction(string $name, string $status): Action
    {
        return Action::make($name)
            ->label(__("kami::admin.actions.{$name}"))
            ->authorize(fn (): bool => EntryResource::can('publish', $this->getRecord()))
            ->action(function () use ($status): void {
                $this->status = $status;
                $this->save();
            });
    }
}
