<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal\EntryResource;

use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use LogicException;
use Psyao\Kami\Admin\Internal\EntryResource;
use Psyao\Kami\Admin\Internal\RichTextInput;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Fields\Field;
use Psyao\Kami\Content\Fields\RichText;
use Psyao\Kami\Content\GlobalSet;
use Psyao\Kami\Content\InlineMarkdown;
use Psyao\Kami\Content\Internal\References;
use Psyao\Kami\Content\Internal\Translation;
use Psyao\Kami\Content\RichText\Document;
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

    /** The Entry's `updated_at` as this page loaded it or last saved it, in seconds: `SaveEntry` refuses a save over a change made since, in any Locale. */
    #[Locked]
    public ?int $updatedAt = null;

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
        $this->updatedAt = $this->getRecord()->updated_at?->getTimestamp();
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

    /** A Singleton has no list to lead back to; an Entry under a Parent goes through its Parents. */
    public function getBreadcrumbs(): array
    {
        if (EntryResource::type() instanceof Singleton) {
            return [];
        }

        return [...EntryResource::levelBreadcrumbs($this->getRecord()->parent_id, $this), ...array_slice(parent::getBreadcrumbs(), 1, preserve_keys: true)];
    }

    protected function getCancelFormAction(): Action
    {
        return EntryResource::cancelToLevel(parent::getCancelFormAction(), fn (): ?string => $this->getRecord()->parent_id);
    }

    /** The Locale edited and its Translation's state, read on every render: a save changes it. A Global Set, always published, shows its Locale only. */
    public function getSubheading(): ?string
    {
        $locale = EntryResource::locales() === [] ? null : (string) __('kami::admin.locale', ['locale' => $this->locale]);

        if (EntryResource::type() instanceof GlobalSet) {
            return $locale;
        }

        $translation = $this->getRecord()->translations->whereIn('locale', [$this->locale, '*'])->first();
        $scheduledAt = EntryResource::scheduledAt($translation, $this->form->getDefaultDateTimeDisplayFormat());
        $state = match (true) {
            $scheduledAt !== null => __('kami::admin.state.scheduled_at', ['date' => $scheduledAt]),
            ! $translation instanceof Translation => __('kami::admin.state.no_translation'),
            default => __('kami::admin.state.' . EntryResource::state($translation)),
        };

        return implode(' · ', array_filter([$locale, $state]));
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
            $values[$key] = match (true) {
                $value instanceof Collection => $value->modelKeys(),
                $value instanceof Document => RichTextInput::fill($value),
                $value instanceof InlineMarkdown => $value->raw(),
                default => $value,
            };
        }

        // What saving a new Translation would write, as `SaveEntry` does when a value is left out; a Shared Field keeps its stored value.
        if ($entry->translations->whereIn('locale', [$this->locale, '*'])->isEmpty()) {
            foreach (EntryResource::type()->flatFields() as $field) {
                $default = $field->defaultValue();

                if (EntryResource::type()->isTranslatable($field) && $default !== null) {
                    $values[$field->handle] = $field instanceof RichText && is_array($default) ? RichTextInput::toEditor($default) : $default;
                }
            }
        }

        if (EntryResource::type()->isHierarchical()) {
            $values['parent'] = $entry->parent_id;
        }

        return $values;
    }

    protected function handleRecordUpdate(Model $record, array $data): Entry
    {
        try {
            $this->record = $entry = EntryResource::save($data, $this->getRecord(), $this->status, $this->locale, $this->data ?? [], $this->updatedAt === null ? null : CarbonImmutable::createFromTimestamp($this->updatedAt));
        } catch (ValidationException $exception) {
            // No input holds the Entry's date: the refusal shows on a notification, kept until dismissed.
            $message = $exception->validator->errors()->first('data.updated_at');

            if ($message !== '') {
                Notification::make()->danger()->title($message)->persistent()->send();
            }

            throw $exception;
        }

        $this->updatedAt = $entry->updated_at?->getTimestamp();

        // The editor shows what was stored, not what it held: `SaveEntry` normalised it.
        if (array_filter(EntryResource::type()->flatFields(), fn (Field $field): bool => $field instanceof RichText) !== []) {
            $this->fillForm();
        }

        return $entry;
    }

    protected function getHeaderActions(): array
    {
        return [
            ...(count(EntryResource::locales()) > 1 ? [$this->localeSwitcher()] : []),
            Action::make('view')
                ->label(__('kami::admin.actions.view'))
                ->color('gray')
                ->url(fn (): ?string => $this->getRecord()->url(), shouldOpenInNewTab: true)
                ->visible(fn (): bool => $this->getRecord()->url() !== null),
            ...EntryResource::plugin()->entryForm(EntryResource::type(), $this)->actions(),
        ];
    }

    /** Every Locale with its state, labelled through closures: a save on this page changes them. Each other Locale is a link, keeping M2's URLs. */
    private function localeSwitcher(): ActionGroup
    {
        $default = Site::current()->defaultLocale();

        return ActionGroup::make(array_map(function (string $locale) use ($default): Action {
            $action = Action::make("locale_{$locale}")->label(fn (): string => $this->localeLabel($locale))->color('gray');

            return $locale === $this->locale
                ? $action->icon(Heroicon::Check)->disabled()->extraAttributes(['aria-current' => 'true'])
                : $action->url(fn (): string => EntryResource::editUrl($this->getRecord(), $locale === $default ? null : $locale));
        }, EntryResource::locales()))
            ->label(fn (): string => $this->localeLabel($this->locale))
            ->icon(Heroicon::Language)
            ->color('gray')
            ->button();
    }

    private function localeLabel(string $locale): string
    {
        $state = EntryResource::state($this->getRecord()->translations->firstWhere('locale', $locale));

        return (string) __('kami::admin.locale_state', ['locale' => $locale, 'state' => __($state === 'missing' ? 'kami::admin.state.no_translation' : "kami::admin.state.{$state}")]);
    }

    protected function getFormActions(): array
    {
        if (EntryResource::type() instanceof GlobalSet) {
            return [$this->getSaveFormAction(), $this->getCancelFormAction()];
        }

        return [
            $this->getSaveFormAction(),
            $this->statusAction('publish', Translation::PUBLISHED)
                ->label(fn (): string => EntryResource::publishLabel($this->data['published_at'] ?? null))
                ->visible(fn (): bool => $this->getRecord()->status !== Translation::PUBLISHED),
            // ponytail: no confirmation listing a Parent's children, as none becomes unreachable while Paths ignore ancestors; #266 adds it with M10's hierarchical URLs.
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
