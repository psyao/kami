<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Facades\Kami;
use Psyao\Kami\Forms\Form;
use Psyao\Kami\Forms\Submission;
use Workbench\App\Content\Home;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Laravel reads the namespace by matching the app path against composer.json: read it before the path moves.
    $this->app->getNamespace();
    $this->site = sys_get_temp_dir() . '/kami-make-form-' . uniqid();
    $this->app->useAppPath("{$this->site}/app");
    config(['view.paths' => ["{$this->site}/views"]]);
});

afterEach(fn () => File::deleteDirectory($this->site));

/** The generated `MadeContactForm`, loaded once: each test writes it under its own temporary path. */
function madeContactForm(): string
{
    $class = app()->getNamespace() . 'Forms\MadeContactForm';

    if (! class_exists($class, false)) {
        require app_path('Forms/MadeContactForm.php');
    }

    return $class;
}

it('writes a final Form class and its view, a trailing Form in the name or not', function (string $name): void {
    $this->artisan('kami:make:form', ['name' => $name])
        ->expectsOutputToContain('Forms/MadeContactForm.php')
        ->expectsOutputToContain('forms/made_contact.blade.php')
        ->assertSuccessful();

    $class = new ReflectionClass(madeContactForm());
    $form = $class->newInstance();

    expect($class->isFinal())->toBeTrue()
        ->and($form)->toBeInstanceOf(Form::class)
        ->and($form->handle())->toBe('made_contact')
        ->and(array_keys($form->rules()))->toBe(['name', 'email', 'message'])
        ->and($form->replyTo())->toBe('email')
        // As the Workbench's ContactForm: a site's Rector would narrow `?string`, the parent's signature, at its first commit (#540).
        ->and(File::get(app_path('Forms/MadeContactForm.php')))->toContain('final class MadeContactForm extends Form', 'public function replyTo(): string')
        ->and(File::get("{$this->site}/views/forms/made_contact.blade.php"))
        ->toContain('<x-kami::form form="made_contact">')
        ->toContain('<x-kami::form.errors level="3" />');
})->with(['MadeContact', 'MadeContactForm']);

it('prints the lines a site adds elsewhere', function (): void {
    $this->artisan('kami:make:form', ['name' => 'QuoteRequest'])
        ->expectsOutputToContain($this->app->getNamespace() . 'Forms\QuoteRequestForm::class,')
        ->expectsOutputToContain('recipients()')
        ->expectsOutputToContain("'quote_request' => ['label' => 'Quote Request', 'send' => 'Send'],")
        ->expectsOutputToContain("'name' => 'name', 'email' => 'email address', 'message' => 'message',")
        ->expectsOutputToContain('php artisan lang:add <locale>')
        ->expectsOutputToContain("@include('forms.quote_request')")
        ->assertSuccessful();
});

it('refuses to overwrite the class or the view, writing neither', function (string $existing, string $other): void {
    File::ensureDirectoryExists(dirname("{$this->site}/{$existing}"));
    File::put("{$this->site}/{$existing}", 'mine');

    $this->artisan('kami:make:form', ['name' => 'Contact'])
        ->expectsOutputToContain('already exists')
        ->assertFailed();

    expect(File::get("{$this->site}/{$existing}"))->toBe('mine')
        ->and(File::exists("{$this->site}/{$other}"))->toBeFalse();
})->with([
    'the class' => ['app/Forms/ContactForm.php', 'views/forms/contact.blade.php'],
    'the view' => ['views/forms/contact.blade.php', 'app/Forms/ContactForm.php'],
]);

it('refuses a name that cannot be a Form', function (string $name, string $message): void {
    $this->artisan('kami:make:form', ['name' => $name])
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect(File::exists(app_path('Forms')))->toBeFalse();
})->with([
    'a path' => ['Forms/Contact', 'not a name a Form can take'],
    'lowercase' => ['contact', 'not a name a Form can take'],
    'Form alone' => ['Form', 'not a name a Form can take'],
    'the id of one of its fields' => ['MessageForm', 'also the id of one of its fields'],
    'too long a handle' => [str_repeat('A', 21), 'longer than 40'],
]);

it('generates a Form that renders on a page, shows its errors, posts and stores', function (): void {
    $this->artisan('kami:make:form', ['name' => 'MadeContact'])->assertSuccessful();
    config(['kami.forms' => [madeContactForm()]]);
    // The Workbench home page, holding the generated Form as a site's page view would.
    File::ensureDirectoryExists("{$this->site}/views/kami");
    File::put("{$this->site}/views/kami/home.blade.php", "@include('forms.made_contact')");
    View::getFinder()->prependLocation("{$this->site}/views");
    Kami::routes();
    $page = resolve(SaveEntry::class)->handle(Home::class, ['title' => 'Accueil', 'status' => 'published'], locale: 'fr');

    $this->get('/')->assertOk()
        ->assertSee('<section aria-labelledby="made_contact-heading">', escape: false)
        ->assertSee('<form method="post" action="/kami/forms/made_contact" id="made_contact" class="kami-form">', escape: false)
        ->assertSee('<input type="email" id="email" name="email"', escape: false);
    $send = fn (array $values) => $this->withHeaders(['Sec-Fetch-Site' => 'same-origin'])->post('/kami/forms/made_contact', [
        '_kami_origin' => "{$page->id}:fr",
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'message' => 'Hello, I would like to talk.',
        ...$values,
    ]);

    $send(['email' => 'not an address'])->assertUnprocessable()
        ->assertSee('<a href="#email">', escape: false)
        ->assertSee('aria-describedby="email-error"', escape: false)
        ->assertSee('value="Ada Lovelace"', escape: false);
    $send([])->assertRedirect('http://localhost?sent=made_contact#made_contact');

    expect(Submission::query()->sole()->data)->toBe(['name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'message' => 'Hello, I would like to talk.']);
    $this->get('/?sent=made_contact')->assertOk()->assertSee('<p id="made_contact" role="status">', escape: false);
});
