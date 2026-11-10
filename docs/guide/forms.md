# Forms

A Form lets a visitor write to the site, such as a contact form. You declare it as a class with Laravel validation rules and write its inputs yourself in your page's view. Kami adds what protects it, stores each Submission, and keeps the page cached: a Form adds no session, cookie or CSRF token to the page (ADR 0015).

## Declaring a Form

A Form is a class extending `Psyao\Kami\Forms\Form`, listed in `config/kami.php`:

```php
namespace App\Forms;

use Psyao\Kami\Forms\Form;

final class ContactForm extends Form
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
        ];
    }
}
```

```php
'forms' => [
    App\Forms\ContactForm::class,
],
```

- **Handle.** The class name in snake_case, without a trailing `Form`: `ContactForm` is `contact`, `QuoteRequestForm` is `quote_request`. Override `handle()` to choose another. It names the Form in its markup, its route and its confirmation, so changing it changes all three.
- **Rules.** Their keys are the Form's fields, and only their validated values are stored: anything else the request carries is dropped. A nested rule, `topics.*`, stores its parent key, `topics`.
- **Labels.** The Form's name comes from `lang/<locale>/kami-forms.php`, by handle, and each field's name in validation messages from `validation.attributes`:

```php
// lang/fr/kami-forms.php
return [
    'contact' => ['label' => 'Contact'],
];

// lang/fr/validation.php
return [
    'attributes' => ['name' => 'nom', 'email' => 'adresse e-mail'],
];
```

Kami checks `kami.forms` when a Form is first read: every entry must extend `Form`, with a snake_case handle of 40 characters at most, unique among the Forms.

## Writing its markup

Kami renders no input: the markup is yours, in the view of the page that holds the Form. Wrap it in `<x-kami::form>`, and put `<x-kami::form.errors />` first inside it:

```blade
@if (Kami::form('contact')->sent())
    <p id="contact" role="status">{{ __('Thank you, your message is sent.') }}</p>
@else
    <x-kami::form form="contact">
        <x-kami::form.errors />

        <label for="email">{{ __('Email address') }}</label>
        <input type="email" id="email" name="email" value="{{ old('email') }}" required autocomplete="email"
            @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
        @error('email')<p id="email-error">{{ $message }}</p>@enderror

        <label for="message">{{ __('Message') }}</label>
        <textarea id="message" name="message" required maxlength="5000"
            @error('message') aria-invalid="true" aria-describedby="message-error" @enderror>{{ old('message') }}</textarea>
        @error('message')<p id="message-error">{{ $message }}</p>@enderror

        <button type="submit">{{ __('Send') }}</button>
    </x-kami::form>
@endif
```

- **`<x-kami::form form="contact">`** emits the `<form>` tag, posting to the Form's route, with `id="contact"` and the class `kami-form`; other attributes you give it are added. Inside, it adds a honeypot field, hidden from people and from assistive technology, and `_kami_origin`, which names the page and its Locale; neither is a secret. It renders on a page Kami renders only: a form on a route of your own posts to a route of your own ([below](#a-form-with-a-session)).
- **`<x-kami::form.errors />`** renders nothing until a Submission is refused. It then shows a summary with `role="alert"`, which takes the focus when the page loads, with no script, and links to each field. A link targets the field's name as an id, so give each input the `id` of its `name`. Its heading is an `<h2>`; `level="3"` makes it an `<h3>`.
- **HTML constraints.** `required`, `maxlength` and `type="email"` stop most mistakes before the page is sent, so a refusal from the server is rare.
- **Styling.** Both components emit `kami-*` classes and no CSS or script. Their markup is yours to change ([Overriding a component](routing-and-rendering.md#overriding-a-component)).

## After a Submission

Each Submission posts to `POST /kami/forms/{handle}`, named `kami.form`, which `Kami::routes()` registers.

- **Accepted.** The Submission is stored and the visitor is sent back to the page with `?sent=contact#contact`. `Kami::form('contact')->sent()` is true there, so the page shows its confirmation. A URL with a query string is never page-cached, so the confirmation is never served to another visitor.
- **Refused by validation.** The page is rendered again, with a 422 status, `Cache-Control: no-store` and no cookie. `$errors`, `@error` and `old()` work there as in Laravel's `web` group, for this response only. A field named `password`, `password_confirmation` or `current_password` is never filled again.

A refused Submission stores nothing. With no session, a reload of that page asks the browser to send the form again.

## Protection

The route checks, in this order:

1. **The origin.** A browser's `Sec-Fetch-Site` must be `same-origin`; a browser that sends no such header passes when its `Origin` is absent or the Site's. Otherwise: 403. No script can set these headers, so another site cannot make its visitors' browsers post to your Form.
2. **The page.** `_kami_origin` must name an Entry visible on the Site, in one of its Locales. Otherwise: a 422 through your [error pages](routing-and-rendering.md#error-pages).
3. **The honeypot.** When the hidden field is filled, the answer is the normal success, and nothing is stored or counted.
4. **The throttle.** Five Submissions per minute per Form and per IP address; an IPv6 address counts with its whole /64. Above it: 429, with `Retry-After`. The key is hashed in your application's cache, so no address is kept. Only a Submission that passed the checks above counts, so a bot caught by the honeypot does not use up the quota of a person behind the same address.
5. **Validation**, against `rules()`.

A bot that posts directly forges any header, from its own address: the honeypot and the throttle are aimed at it. Kami ships no captcha, which would be a remote service; a self-hosted proof-of-work check fits in `rules()` as a custom rule.

## Submissions

A Submission is a `Psyao\Kami\Forms\Submission` in `kami_form_submissions`: its Site, Locale, Form handle, the page it was sent from, and its validated values in `data`. Kami stores no IP address, user agent or cookie with it.

Your own code stores one through the `StoreSubmission` action, as the route does:

```php
use Psyao\Kami\Forms\Actions\StoreSubmission;

resolve(StoreSubmission::class)->handle('contact', $validated, locale: 'fr', entry: $page);
```

It does not validate: validate first. Once the write is committed, Kami dispatches `Psyao\Kami\Forms\Events\SubmissionStored` with the Submission, the hook for your own side effects:

```php
Event::listen(SubmissionStored::class, function (SubmissionStored $event): void {
    // $event->submission->form, $event->submission->data
});
```

A Submission is not Content: storing one makes no cached page stale and records no Audit log event.

Two methods of a Form describe its Submissions:

- `summary(Submission)`: one line naming it. By default, its first text value, truncated to 100 characters.
- `retention()`: how long it is kept, twelve months by default, `null` to keep it forever.

## A form with a session

A Form posts without a session, so the page stays cached. A form that needs one, such as a form only signed-in members see, stays your own code: your own route in the `web` group, a controller of your own, and `Kami::withoutPageCache()` on the page that holds it, with Laravel's `@csrf` and redirect back with errors. It can still store its Submissions with `StoreSubmission`.

When you mount Kami's pages with `Kami::routes(session: true)` ([Existing application](existing-application.md#keep-your-members-signed-in)), the form route joins your `web` group too: `<x-kami::form>` emits `@csrf`, and Laravel checks the token.

## Shared hosting and server

Both behave the same. The page holding a Form is page-cached like any other. A Submission boots Laravel, validates and writes one row. The throttle uses your default cache store: the `file` store on shared hosting, any store on a server.
