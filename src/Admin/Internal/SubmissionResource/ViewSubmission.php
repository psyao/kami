<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal\SubmissionResource;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ViewRecord;
use Psyao\Kami\Admin\Internal\SubmissionResource;
use Psyao\Kami\Forms\Actions\DeleteSubmissions;
use Psyao\Kami\Forms\Submission;

/** @internal */
final class ViewSubmission extends ViewRecord
{
    #[\Override]
    protected static string $resource = SubmissionResource::class;

    /** Opening it marks it read: what the navigation badge counts. */
    public function mount(int | string $record): void
    {
        parent::mount($record);

        $this->getRecord()->markRead();
    }

    public function getRecord(): Submission
    {
        $record = parent::getRecord();

        return $record instanceof Submission ? $record : abort(404);
    }

    public function getTitle(): string
    {
        return SubmissionResource::formLabel($this->getRecord()->form);
    }

    protected function getHeaderActions(): array
    {
        $replyTo = $this->replyTo();

        return [
            Action::make('reply')->label(__('kami::admin.submissions.reply'))
                // Encoded but for `@`: a valid address may hold `?` or `&`, which would start the link's own headers (RFC 6068).
                ->url($replyTo === null ? null : 'mailto:' . str_replace('%40', '@', rawurlencode($replyTo)))
                ->visible($replyTo !== null),
            DeleteAction::make()
                ->using(function (Submission $record): bool {
                    resolve(DeleteSubmissions::class)->handle([$record], Filament::auth()->user());

                    return true;
                }),
        ];
    }

    /** The visitor's address from the Form's `replyTo()` field, when it holds a valid one, as the mail's `Reply-To` takes it. */
    private function replyTo(): ?string
    {
        $field = SubmissionResource::declaredForm($this->getRecord()->form)?->replyTo();
        $address = $field === null ? null : ($this->getRecord()->data[$field] ?? null);

        return is_string($address) && filter_var($address, FILTER_VALIDATE_EMAIL) !== false ? $address : null;
    }
}
