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

- **Handle.** The class name in snake_case, without a trailing `Form`: `ContactForm` is `contact`, `QuoteRequestForm` is `quote_request`. Override `handle()` to choose another. It names the Form in its markup, its route and the `?sent=` query after a Submission, so changing it changes all three.
- **Rules.** Their keys are the Form's fields, and only their validated values are stored: anything else the request carries is dropped. A nested rule, `topics.*`, stores its parent key, `topics`.
- **Labels.** The Form's name comes from `lang/<locale>/kami-forms.php`, by handle, and so does its Confirmation, the text a visitor reads once their Submission is accepted (Kami's own, "Thank you, your message has been sent.", when you write none). Each field's name in validation messages comes from `validation.attributes`:

```php
// lang/fr/kami-forms.php
return [
    'contact' => ['label' => 'Contact', 'confirmation' => 'Merci, votre message est envoyé.'],
];

// lang/fr/validation.php
return [
    'attributes' => ['name' => 'nom', 'email' => 'adresse e-mail'],
];
```

A Locale other than English also needs Laravel's own validation messages in `lang/<locale>/validation.php`, or a French page shows `The nom field is required.`. [laravel-lang](https://laravel-lang.com) provides them, merged with the `attributes` already there:

```bash
composer require laravel-lang/common --dev
php artisan lang:add fr
```

Kami checks `kami.forms` when a Form is first read: every entry must extend `Form`, with a snake_case handle of 40 characters at most, unique among the Forms.

`kami:make:form` starts one. It writes the class into `app/Forms` and an accessible view of its inputs into `resources/views/forms`, refuses to overwrite either file, and prints what to add elsewhere: the `config/kami.php` line, its strings in `kami-forms.php` and `validation.attributes`, the `lang:add` line for Laravel's validation messages, and the `@include` for the page's view. A trailing `Form` in the name is dropped, and `Name`, `Email` and `Message` are refused: the Form's id, its handle, would be that of one of its fields.

```bash
php artisan kami:make:form Contact
```

It writes a class like the one above, with `replyTo()` naming `email` and `recipients()` left for you to fill ([Delivery](#delivery)), and markup like the one below for its three fields, in a section titled with the Form's label.

## Writing its markup

Kami renders no input: the markup is yours, in the view of the page that holds the Form. Wrap it in `<x-kami::form>`, and put `<x-kami::form.errors />` first inside it:

```blade
@if (Kami::form('contact')->sent())
    <p id="contact" role="status">{{ Kami::form('contact')->confirmation() }}</p>
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
- **Refused by validation.** The page is rendered again, with a 422 status (unless your type's `render()` answers another), `Cache-Control: no-store` and no cookie. `$errors`, `@error` and `old()` work there as in Laravel's `web` group, for this response only. `old()` holds a field's values in the shape its rules allow: an array only where a rule says `array` or `list` or reaches below it (`topics.*`), so a field posted as `name[]=x` comes back empty rather than as an array, and a checkbox group ruled by `tags.*` alone, posted as `tags=b`, comes back empty rather than as a string `in_array()` would refuse. A field named `password`, `password_confirmation` or `current_password` is never filled again.

A refused Submission stores nothing. With no session, a reload of that page asks the browser to send the form again.

`Kami::form('contact')->confirmation()` gives the Confirmation in the page's Locale, the text a script shows too ([Without a reload](#without-a-reload)).

## Protection

The route checks, in this order, the last four through `SubmitForm`, the action a [Livewire component](#from-a-livewire-component) calls too:

1. **The origin.** A browser's `Sec-Fetch-Site` must be `same-origin`; a browser that sends no such header passes when its `Origin` is absent or the Site's. Otherwise: 403. No script can set these headers, so another site cannot make its visitors' browsers post to your Form.
2. **The honeypot.** When the hidden field is filled, the answer is the normal success, back to the page or to the Site's home when the page is unknown, and nothing is stored or counted.
3. **The page.** `_kami_origin` must name an Entry visible on the Site, in one of its Locales. Otherwise: a 422 through your [error pages](routing-and-rendering.md#error-pages).
4. **The throttle.** Five Submissions per minute per Form and per IP address; an IPv6 address counts with its whole /64. Above it: 429, with `Retry-After`. The key is hashed in your application's cache, so no address is kept. Only a Submission that passed the checks above counts, so a bot caught by the honeypot does not use up the quota of a person behind the same address.
5. **Validation**, against `rules()`.

Over plain `http`, a browser never reaches the route: the front's `upgrade-insecure-requests` sends the post to `https`, and the visitor's text is lost. Serve a local site over HTTPS ([Local development over HTTP](security.md#local-development-over-http)).

A bot that posts directly forges any header, from its own address: the honeypot and the throttle are aimed at it. Kami ships no captcha, which would be a remote service; a self-hosted proof-of-work check fits in `rules()` as a custom rule.

## Without a reload

A page reloads after each Submission: a Form in a modal closes, and its errors or Confirmation show on the reloaded page. Your own script can submit it instead and show the answer in place. Kami ships no front script: it answers JSON, and the script below is yours to copy and change.

### The JSON answers

The route answers JSON when the request expects it, as Laravel's `expectsJson()` reads it: `Accept: application/json`. It runs the same checks in the same order ([Protection](#protection)); a request that does not expect JSON keeps the page and the redirect above.

| Status | When | Body |
|---|---|---|
| 200 | Accepted, or caught by the honeypot | `{"message": "…"}`, the Form's Confirmation in the page's Locale |
| 422 | Refused by validation | Laravel's own: `{"message": "…", "errors": {"email": ["…"]}}`, each field's messages, keyed as `rules()` names them (`topics.0` for a wildcard) |
| 403 | Another origin | `{"message": …}` |
| 422 | No known page in `_kami_origin` | `{"message": …}`, with no `errors` |
| 429 | The throttle, with `Retry-After` | `{"message": …}` |

The 200 and the validation 422 carry `Cache-Control: no-store`. The status is the contract for the last three: their `message` is Laravel's, not text for a visitor, so show your own. A honeypot caught answers the same 200 as a Submission accepted, so a bot learns nothing.

### The script

Save it as `public/js/form.js`, or in your Vite entry, and load it from the layout of the page that holds the Form:

```blade
<script src="{{ asset('js/form.js') }}" defer></script>
```

```js
// Submits each Kami Form on the page without a reload, through the Form route's JSON answers.
// Loaded from the site's origin with `defer`: no inline script and no eval, so the front's CSP holds.
for (const form of document.querySelectorAll('form.kami-form')) {
    const status = document.getElementById(`${form.id}-status`);
    const button = form.querySelector('button:not([type]), [type="submit"]');
    // Without its status element or a button, the form posts and the page reloads, as without this script.
    if (!status || !button) {
        continue;
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearErrors(form);
        status.textContent = '';
        button.disabled = true;

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { Accept: 'application/json' },
            });
            // The status decides: a 200 whose body cannot be read is still a Submission stored, never one to send again.
            const body = await response.json().catch(() => ({}));

            if (response.status === 200) {
                form.hidden = true;
                status.textContent = body.message ?? '';
                status.focus();
            } else if (body.errors) {
                showErrors(form, body.errors);
            } else {
                status.textContent = form.dataset.error;
            }
        } catch {
            status.textContent = form.dataset.error;
        } finally {
            button.disabled = false;
        }
    });
}

// A summary that takes the focus and links to each field, and each field's message beside it.
function showErrors(form, errors) {
    const summary = document.createElement('div');
    summary.className = 'kami-form-errors';
    summary.setAttribute('role', 'alert');
    summary.tabIndex = -1;
    const heading = summary.appendChild(document.createElement('h2'));
    heading.className = 'kami-form-errors-heading';
    heading.textContent = form.dataset.errorsHeading;
    const list = summary.appendChild(document.createElement('ul'));

    for (const [name, [message]] of Object.entries(errors)) {
        const link = list.appendChild(document.createElement('li')).appendChild(document.createElement('a'));
        link.href = `#${name}`;
        link.textContent = message;

        const field = form.elements.namedItem(name);
        if (field instanceof Element) {
            const error = document.createElement('span');
            error.id = `${name}-error`;
            error.textContent = message;
            field.after(error);
            field.setAttribute('aria-invalid', 'true');
            field.setAttribute('aria-describedby', [field.getAttribute('aria-describedby'), error.id].filter(Boolean).join(' '));
        }
    }

    form.prepend(summary);
    summary.focus();
}

// Removes the errors of an earlier attempt, the page's own included.
function clearErrors(form) {
    form.querySelector('.kami-form-errors')?.remove();

    for (const field of form.querySelectorAll('[aria-invalid]')) {
        const id = `${field.name}-error`;
        const describedBy = (field.getAttribute('aria-describedby') ?? '').split(' ').filter((token) => token !== id).join(' ');
        field.removeAttribute('aria-invalid');
        describedBy ? field.setAttribute('aria-describedby', describedBy) : field.removeAttribute('aria-describedby');
        document.getElementById(id)?.remove();
    }
}
```

It submits each `form.kami-form` of the page with `fetch()`. `FormData` carries the honeypot and `_kami_origin`, and the browser sends `Sec-Fetch-Site` as for a page's own post. The file is served from your origin, with no inline script and no `eval`, so the front's policy holds as it is ([Security](security.md)), and the page holding the Form stays cached.

### Its markup

Give the form the texts the script shows, and add an empty status element after it, with the form's id and `-status`:

```blade
<x-kami::form form="contact" data-error="{{ __('Your message could not be sent. Please try again later.') }}"
    data-errors-heading="{{ __('kami::forms.errors.heading') }}">
    {{-- … --}}
</x-kami::form>
<p id="contact-status" role="status" tabindex="-1"></p>
```

- **Accepted.** The form is hidden, and the Confirmation goes in the status element, which takes the focus. It is a live region present when the page loads, so a screen reader announces it.
- **Refused by validation.** The script builds the summary `<x-kami::form.errors />` renders, with `role="alert"`, and moves the focus to it. Each field gets `aria-invalid="true"` and its message in an element `{name}-error`, added to its `aria-describedby`. Change the summary's `h2` to the level `<x-kami::form.errors>` has on your page. A field the summary links to needs the `id` of its `name`, as without the script. A field the script cannot find by its name, a radio or checkbox group, or a wildcard key such as `topics.0`, gets its message in the summary only: mark such a group up yourself if it needs more.
- **Anything else**, a 403, a 429, a server error or no network: the status element shows `data-error`, and the visitor's input stays.
- **While the request runs**, the submit button is disabled, so a second click sends nothing.
- **Without JavaScript**, nothing changes: the form posts, and the page reloads. So does a form with no status element or no submit button: the script leaves it alone.

## From a Livewire component

A Livewire component of your own, on a page Kami renders, stores its Submissions through `Psyao\Kami\Forms\Actions\SubmitForm`, with the route's protection: the honeypot, the page, the throttle and validation, in the [same order](#protection). Its snapshot sits in a cached page that anyone can read, and a bot can post it back with forged headers, so a component that called `StoreSubmission` itself would skip the honeypot and the throttle.

```php
use Livewire\Component;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Forms\Actions\SubmitForm;

final class Contact extends Component
{
    public Entry $entry;

    public string $locale = 'fr';

    public string $name = '';

    public string $email = '';

    public string $message = '';

    public string $kami_website = '';

    public bool $sent = false;

    public function send(SubmitForm $submit): void
    {
        $this->resetErrorBag();
        $submit->handle('contact', $this->only(['name', 'email', 'message', SubmitForm::HONEYPOT]), $this->entry, $this->locale);
        $this->sent = true;
    }

    // render() …
}
```

Mount it from the page's view with `<livewire:contact :entry="$entry" :locale="app()->getLocale()" />`, and give it a field `wire:model="kami_website"`, hidden as `<x-kami::form>` hides its own.

`handle($form, $input, $entry, $locale)` takes the Form or its handle, the visitor's values with the honeypot's, the page and its Locale:

- **Accepted.** It returns the stored `Submission`, in that Locale.
- **Honeypot filled.** It returns `null`, storing and counting nothing: show the normal success, so a bot learns nothing.
- **Refused by validation.** It throws Laravel's `ValidationException`: Livewire shows its errors through `$errors` and `@error`, in the page's Locale. `$this->resetErrorBag()` first clears the previous attempt's errors.
- **The page is no longer visible** in that Locale: a 422. **Above the throttle**: a 429 with `Retry-After`. Both are `HttpException`s: Livewire answers its update with that status.

The visitor's origin stays Livewire's to check: its update route runs your `web` group, whose CSRF check refuses a cross-site post. A controller of your own calls the action the same way, behind its own CSRF or origin check.

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

### Retention

`kami:forms:prune`, which `Kami::schedule()` runs daily, deletes for good each Submission older than its Form's `retention()`, its deliveries with it, so nobody has to remember to:

```php
use Carbon\CarbonInterval;

public function retention(): ?CarbonInterval
{
    return CarbonInterval::months(6);
}
```

- **`null`** keeps a Form's Submissions forever. A retention of zero or less is a typo: the command deletes none of that Form's Submissions, names the Form and fails, after pruning the others.
- **A Form no longer declared** under `kami.forms` has no retention to read: its Submissions are kept, never pruned blindly, and `kami:doctor` lists them. Declare it again, or delete them in the admin; with no Form left, the admin has no Submissions screen, and `kami:doctor` prints the `DeleteSubmissions` call to run from `php artisan tinker`.
- **Not an editor's deletion.** Expiry records no Audit log event and dispatches no `SubmissionsDeleted`, which only follows a deletion through `DeleteSubmissions`: a copy your code keeps elsewhere is not told, so give it a retention of its own.

## Delivery

Each Submission reaches the site owner as a mail, sent after the visitor's response: the visitor waits for no mail server.

```php
final class ContactForm extends Form
{
    public function recipients(): array
    {
        return ['hello@example.com'];
    }

    public function replyTo(): string
    {
        return 'email';
    }

    // rules()…
}
```

- **The mail.** `Psyao\Kami\Forms\SubmissionReceived`, in plain text: its subject is `[{Form label}] {summary}`, its body each field with its `validation.attributes` label, in the order of `rules()`. It is written in the Admin language, `app.locale`, whatever the page's Locale. `replyTo()` names the field holding the visitor's address, which goes on `Reply-To` so that answering writes back to them. The mail ends with a link to the Submission in the admin, on the first panel carrying `KamiPlugin`; from the console, the link is built from `APP_URL`.
- **Through Laravel's mailer.** Kami adds no mail setting: configure `MAIL_*` in `.env` for SMTP ([Hosting](hosting.md#mail)). The `log` and `array` mailers keep mail on the server, so `kami:doctor` fails in production while a Form is declared and one of them is the default. It warns whenever the default mailer is an `smtp` one with no `timeout` ([Hosting](hosting.md#mail)), and only informs under `APP_ENV=local` or `testing`.
- **No recipient.** `recipients()` returns none by default: the Submission is stored, nothing is mailed, a warning is logged and `kami:doctor` lists the Form. Adding a recipient later mails the Submissions that follow, never those stored before.
- **Never queued.** Kami sends with `Notification::sendNow()`, after the response through `defer()`, so no queue worker is needed.

### Retries

Kami writes one delivery per channel, in the Submission's transaction, then tries each once the response is sent. A channel that fails is recorded with its error and never stops the others. `kami:forms:deliver`, which `Kami::schedule()` runs every 15 minutes, retries a failed delivery at its next run, so within 15 minutes, and leaves it once it has failed 5 times. It tries up to 50 deliveries per run, and starts none after 20 seconds, or after the budget left to the Tick running it if that is shorter, so that a mail server that hangs never holds a Tick past its budget. It then names the failed deliveries left waiting, and the time of its next run.

Each attempt first claims its delivery, so a Submission is sent once even when `defer()` is still sending it while a Tick runs `kami:forms:deliver`. An attempt that PHP stops mid-send, out of time or memory, leaves its claim and no error: it is retried, and `kami:doctor` reports it, once the claim is an hour old. A delivery is at least once: a mail server that accepted a mail but answered too late gets it again.

`kami:doctor` warns, as soon as an attempt has failed, about deliveries that wait for a retry, those abandoned, with the last error, and the Submissions of a Form no longer declared under `kami.forms`.

### A notification of your own

Override `notification()` to send another Laravel notification, through any channel, such as a chat webhook:

```php
use Illuminate\Notifications\Notification;
use Psyao\Kami\Forms\Submission;

public function notification(Submission $submission): Notification
{
    return new ContactReceived($this, $submission);
}
```

```php
final class ContactReceived extends Notification
{
    public function __construct(private ContactForm $form, private Submission $submission) {}

    public function via(object $notifiable): array
    {
        return ['mail', ChatChannel::class];
    }

    public function toMail(object $notifiable): MailMessage { /* … */ }

    public function toChat(object $notifiable): array { /* … */ }
}
```

```php
final class ChatChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        Http::timeout(5)->throw()->post(config('services.chat.webhook'), $notification->toChat($notifiable));
    }
}
```

- Each channel of `via()` is delivered and retried on its own; a channel signals a failure by throwing, as `->throw()` does above. Keep each short, with a timeout: it runs after a visitor's response, or inside a Tick.
- The notifiable is an on-demand one, routed to `recipients()` for `mail`: a channel of your own reads its target from your configuration.
- Do not implement `ShouldQueue`: a queued notification waits for a Tick to work through the queue, and Kami's retry no longer sees it fail.
- It is sent in the Admin language unless it sets its own `locale()`.

## In the admin

Once a Form is declared, the admin's **Submissions** screen lists the current Site's Submissions, newest first, a tab per Form. Each row shows the date in the Site's time zone, the Locale, the Form's `summary()` and the delivery state: delivered, pending, retrying after a failure, or failed once a channel was abandoned. The navigation counts the unread ones.

Opening a Submission marks it read. It shows each field with its `validation.attributes` label, in the order of `rules()`, the visitor's text always escaped, then each channel's delivery with its attempts and last error. When `replyTo()` names a field holding a valid address, a **Reply** button opens your mail client to it.

A Form you remove from `kami.forms` keeps its Submissions: they show under a tab named by its handle, their fields sorted by key, so that you can still read and delete them. The screen needs at least one declared Form: with none left, it is gone, and the Submissions wait in the table, where `kami:doctor` reports them with the `DeleteSubmissions` call that deletes them.

Reading takes `kami.form-submissions.view`; deleting, one Submission or several at once, takes `kami.form-submissions.delete` ([Users and Roles](users-and-roles.md#permissions)). Deletion is for good, with the deliveries, and the [Audit log](audit-log.md) records it per Form with the count, never the content. Your own code deletes Submissions through the same action, for instance to honour a visitor's deletion request:

```php
use Psyao\Kami\Forms\Actions\DeleteSubmissions;
use Psyao\Kami\Forms\Submission;

$theirs = Submission::query()->where('form', 'contact')->where('data->email', $email)->get();
resolve(DeleteSubmissions::class)->handle($theirs);
```

It does not check Permissions: check them before calling it from a route of your own. Once the deletion is committed, it dispatches `Psyao\Kami\Forms\Events\SubmissionsDeleted` per Form and Site, with `form`, `site`, the `submissionIds` actually deleted, and `actor`: the rows are gone, so it carries values only. `$submission->markRead()` marks one read, as opening it does.

## A form with a session

A Form posts without a session, so the page stays cached. A form that needs one, such as a form only signed-in members see, stays your own code: your own route in the `web` group, a controller of your own, and `Kami::withoutPageCache()` on the page that holds it, with Laravel's `@csrf` and redirect back with errors. It can still store its Submissions with `StoreSubmission`.

When you mount Kami's pages with `Kami::routes(session: true)` ([Existing application](existing-application.md#keep-your-members-signed-in)), the form route joins your `web` group too: `<x-kami::form>` emits `@csrf`, and Laravel checks the token.

## Shared hosting and server

Both behave the same. The page holding a Form is page-cached like any other. A Submission boots Laravel, validates and writes one row per Submission and per channel. The throttle uses your default cache store: the `file` store on shared hosting, any store on a server.

Delivery runs after the response through `defer()`, over SMTP: shared hosting often has no `mail()` or `sendmail`. Under PHP-FPM the visitor has their answer first; under `mod_php` the response waits for the mail server. Retries and the retention run from any Tick: the planner's URL or visits on shared hosting, cron's `kami:tick` on a server ([Scheduler](scheduler.md)).
