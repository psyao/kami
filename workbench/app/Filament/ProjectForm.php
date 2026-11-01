<?php

declare(strict_types=1);

namespace Workbench\App\Filament;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Psyao\Kami\Admin\EntryForm;

/** A site's own form: the generated one, with a line of text and a header action. */
final class ProjectForm extends EntryForm
{
    public function components(): array
    {
        return [Text::make('Drafts stay private until published.'), ...parent::components()];
    }

    public function actions(): array
    {
        return [
            Action::make('notify_client')
                ->label('Notify the client')
                ->action(fn (): Notification => Notification::make()->title('Client notified')->success()->send()),
        ];
    }
}
