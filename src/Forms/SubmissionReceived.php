<?php

declare(strict_types=1);

namespace Psyao\Kami\Forms;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Psyao\Kami\Forms\Internal\Forms;
use Psyao\Kami\Support\Internal\Install\PluginRegistration;

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
        // The admin's page of the Submission, when a panel carries `KamiPlugin` (ADR 0011).
        $url = resolve(PluginRegistration::class)->submissionUrl;
        $message = (new MailMessage)
            ->subject(trim("[{$this->form->label()}] {$this->form->summary($this->submission)}"))
            ->text('kami::mail.submission', [
                'intro' => __('kami::forms.mail.intro', ['form' => $this->form->label()]),
                'fields' => Forms::values($this->submission, $this->form),
                'link' => $url === null ? null : __('kami::forms.mail.link', ['url' => $url($this->submission)]),
            ]);

        $replyTo = $this->form->replyTo() === null ? null : ($this->submission->data[$this->form->replyTo()] ?? null);

        // The route validated it; a Submission stored by a site's own code may not have been.
        return is_string($replyTo) && filter_var($replyTo, FILTER_VALIDATE_EMAIL) !== false ? $message->replyTo($replyTo) : $message;
    }
}
