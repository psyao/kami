<?php

// PROTOTYPE, does not run (no Filament here). The escape hatch to full Filament.
// It lives in the site's app/Filament/, the only place that sees Filament, as Kami's own
// Psyao\Kami\Admin does (ADR 0011). The Content Type class never changes.

namespace App\Filament\Forms;

use AguetDev\Content\Project;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Psyao\Kami\Admin\EntryForm;

// Registered in the panel: KamiPlugin::make()->forms([Project::class => ProjectForm::class])
final class ProjectForm extends EntryForm // one of the few non-final extension points
{
    public function configure(Schema $schema): Schema
    {
        // Level 1: chain Filament on the component Kami generated for a declared Field.
        $this->component('url')
            ->prefixIcon('heroicon-o-link')
            ->suffixAction(/* open the URL */);

        // Level 2: rearrange freely. $this->components() is every generated component
        // keyed by handle; any declared Field left out is a boot error, so nothing a
        // declaration stores can silently disappear from the form.
        return $schema->components([
            Grid::make(2)->schema([
                $this->component('client'),
                $this->component('role'),
            ]),
            $this->component('summary'),
            $this->component('url'),
            $this->component('featured')->inline(),
            $this->component('tags'),
        ]);

        // Not allowed: a component with no declared Field behind it (TextInput::make('extra')).
        // Storage comes only from declarations, so it would have no column (ADR 0006).
    }
}
