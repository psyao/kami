<?php

declare(strict_types=1);

namespace Psyao\Kami\Forms;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Psyao\Kami\Forms\Internal\Forms;

/**
 * The default notification of a Submission: a plain-text mail to the Form's `recipients()`, in the Admin language, with the visitor's
 * address on `Reply-To`. A Form overrides `notification()` to send another, through other channels.
 */
final class SubmissionReceived extends Notification
{
    public function __construct(private readonly Form $form, private readonly Submission $submission) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // ponytail: no link to the Submission in the admin until SubmissionResource exists; #484 adds it.
        $message = (new MailMessage)
            ->subject(trim("[{$this->form->label()}] {$this->form->summary($this->submission)}"))
            ->text('kami::mail.submission', [
                'intro' => __('kami::forms.mail.intro', ['form' => $this->form->label()]),
                'fields' => $this->fields(),
            ]);

        $replyTo = $this->form->replyTo() === null ? null : ($this->submission->data[$this->form->replyTo()] ?? null);

        // The route validated it; a Submission stored by a site's own code may not have been.
        return is_string($replyTo) && filter_var($replyTo, FILTER_VALIDATE_EMAIL) !== false ? $message->replyTo($replyTo) : $message;
    }

    /**
     * Each stored value with its `validation.attributes` label, in the order of `rules()`; two fields may share a label.
     *
     * @return list<array{label: string, value: string}>
     */
    private function fields(): array
    {
        $fields = [];

        foreach (Forms::fields($this->form) as $key) {
            if (array_key_exists($key, $this->submission->data)) {
                $label = Lang::has("validation.attributes.{$key}") ? (string) __("validation.attributes.{$key}") : Str::headline($key);
                $fields[] = ['label' => Str::ucfirst($label), 'value' => self::text($this->submission->data[$key])];
            }
        }

        return $fields;
    }

    private static function text(mixed $value): string
    {
        return match (true) {
            is_array($value) => implode(', ', array_map(self::text(...), $value)),
            is_bool($value) => $value ? '✓' : '✗',
            is_scalar($value) => (string) $value,
            default => '',
        };
    }
}
