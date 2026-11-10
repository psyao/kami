<?php

declare(strict_types=1);

namespace Workbench\App\Forms;

use Psyao\Kami\Forms\Form;

/** The Workbench Site's contact form, on the Home page. */
final class ContactForm extends Form
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            // No `minlength` in the markup: the Browser suite sends a message the browser accepts and the server refuses.
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ];
    }

    public function recipients(): array
    {
        return ['hello@example.com'];
    }

    public function replyTo(): string
    {
        return 'email';
    }
}
