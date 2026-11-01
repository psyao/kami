<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Contracts\HasSchemas;
use Livewire\Component as LivewireComponent;
use LogicException;
use Psyao\Kami\Admin\Internal\EntryResource;

/**
 * A Content Type's form in the admin, registered with `KamiPlugin::make()->forms([...])`. Extend it to chain Filament calls, rearrange the components, and add layout, `Actions` or text; storage still follows the declaration (ADR 0007).
 */
class EntryForm
{
    /** @var list<Component>|null */
    private ?array $generated = null;

    /** @internal */
    final public function __construct(private readonly HasSchemas & LivewireComponent $livewire) {}

    /**
     * What the form shows. It holds every input the generated form has and no other: anything else stops the page when the form is built.
     *
     * @return array<Component|Action|ActionGroup>
     */
    public function components(): array
    {
        return $this->generated ??= EntryResource::generatedForm($this->livewire);
    }

    /**
     * The edit page's header actions.
     *
     * @return array<Action|ActionGroup>
     */
    public function actions(): array
    {
        return [];
    }

    /** A generated component by its handle: `client`, `title`, or `address.city` inside a Group. The same one `parent::components()` holds. */
    final protected function field(string $handle): Component
    {
        $this->generated ??= EntryResource::generatedForm($this->livewire);

        return EntryResource::inputs($this->generated, $this->livewire)[$handle]
            ?? throw new LogicException(static::class . " names [{$handle}], which the generated form does not have.");
    }
}
