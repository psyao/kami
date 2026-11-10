<?php

declare(strict_types=1);

namespace Psyao\Kami\Forms;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

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
     * Each stored value with its `validation.attributes` label, in the order of `rules()`.
     *
     * @return array<string, string>
     */
    private function fields(): array
    {
        $fields = [];

        foreach (array_unique(array_map(fn (string $key): string => Str::before($key, '.'), array_keys($this->form->rules()))) as $key) {
            if (! array_key_exists($key, $this->submission->data)) {
                continue;
            }

            $label = Lang::has("validation.attributes.{$key}") ? (string) __("validation.attributes.{$key}") : Str::headline($key);
            $fields[Str::ucfirst($label)] = self::text($this->submission->data[$key]);
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
