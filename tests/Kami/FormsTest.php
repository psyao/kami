<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Facades\Kami;
use Psyao\Kami\Forms\Actions\StoreSubmission;
use Psyao\Kami\Forms\Events\SubmissionStored;
use Psyao\Kami\Forms\Form;
use Psyao\Kami\Forms\Submission;
use Workbench\App\Content\Home;
use Workbench\App\Forms\ContactForm;

uses(RefreshDatabase::class);

/** A Form named with `Form` inside its name, not at its end. */
final class ContactFormV2 extends Form
{
    public function rules(): array
    {
        return [];
    }
}

/** A Form named without the `Form` suffix. */
final class QuoteRequest extends Form
{
    public function rules(): array
    {
        return [];
    }
}

beforeEach(function () {
    Kami::routes();
});

function contactPage(): Entry
{
    $save = resolve(SaveEntry::class);
    $home = $save->handle(Home::class, ['title' => 'Accueil', 'status' => 'published'], locale: 'fr');

    return $save->handle(Home::class, ['title' => 'Home', 'status' => 'published'], $home, 'en');
}

/**
 * A browser's POST from the page: a valid Submission unless overridden.
 *
 * @param  array<string, string>  $values
 */
function sendContact(Entry $page, array $values = [], string $locale = 'fr', array $headers = ['Sec-Fetch-Site' => 'same-origin']): TestResponse
{
    return test()->withHeaders($headers)->post('/kami/forms/contact', [
        '_kami_origin' => "{$page->id}:{$locale}",
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'message' => 'Hello, I would like to talk.',
        ...$values,
    ]);
}

it('stores a valid Submission and answers 303 to the page with ?sent=', function () {
    Event::fake([SubmissionStored::class]);
    $page = contactPage();

    $response = sendContact($page, ['extra' => 'dropped'], locale: 'en');

    $response->assertStatus(303)->assertRedirect('http://localhost/en?sent=contact#contact');
    $submission = Submission::query()->sole();
    expect($submission->only(['site', 'locale', 'form', 'entry_id', 'data']))->toBe([
        'site' => 'default',
        'locale' => 'en',
        'form' => 'contact',
        'entry_id' => $page->id,
        'data' => ['name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'message' => 'Hello, I would like to talk.'],
    ]);
    Event::assertDispatched(SubmissionStored::class, fn (SubmissionStored $event): bool => $event->submission->is($submission));
});

it('checks the origin: Sec-Fetch-Site when sent, else an absent or matching Origin', function (array $headers, int $status) {
    $page = contactPage();

    $response = sendContact($page, headers: $headers);

    $response->assertStatus($status);
    expect(Submission::query()->count())->toBe($status === 303 ? 1 : 0);
})->with([
    'same-origin' => [['Sec-Fetch-Site' => 'same-origin'], 303],
    'same-site' => [['Sec-Fetch-Site' => 'same-site', 'Origin' => 'http://localhost'], 403],
    'cross-site' => [['Sec-Fetch-Site' => 'cross-site', 'Origin' => 'http://localhost'], 403],
    'the Site\'s Origin' => [['Origin' => 'http://localhost'], 303],
    'another Origin' => [['Origin' => 'http://evil.test'], 403],
    'another port' => [['Origin' => 'http://localhost:8080'], 403],
    'no header' => [[], 303],
]);

it('matches Origin as a browser sends it, against a Site URL with capitals or a default port', function (string $url) {
    config(['kami.sites.default.url' => $url]);
    $page = contactPage();

    sendContact($page, headers: ['Origin' => 'https://example.com'])->assertStatus(303);
})->with(['capitals' => 'https://Example.COM', 'default port' => 'https://example.com:443']);

it('answers a filled honeypot with the normal success, storing and counting nothing', function () {
    Event::fake([SubmissionStored::class]);
    $page = contactPage();

    foreach (range(1, 6) as $ignored) {
        sendContact($page, ['kami_website' => 'https://spam.test'])->assertStatus(303)->assertRedirect('http://localhost?sent=contact#contact');
    }

    expect(Submission::query()->count())->toBe(0);
    Event::assertNotDispatched(SubmissionStored::class);
    sendContact($page)->assertStatus(303);
    expect(Submission::query()->count())->toBe(1);
});

it('answers a filled honeypot with the normal success even when the page is unknown', function () {
    $response = test()->withHeaders(['Sec-Fetch-Site' => 'same-origin'])->post('/kami/forms/contact', ['_kami_origin' => 'forged', 'kami_website' => 'https://spam.test']);

    $response->assertStatus(303)->assertRedirect('http://localhost?sent=contact#contact');
    expect(Submission::query()->count())->toBe(0);
});

it('refuses a missing or invalid origin page with a 422 error page, storing nothing', function (Closure $origin) {
    $page = contactPage();

    $response = test()->withHeaders(['Sec-Fetch-Site' => 'same-origin'])->post('/kami/forms/contact', [
        '_kami_origin' => $origin($page),
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'message' => 'Hello, I would like to talk.',
    ]);

    $response->assertUnprocessable()->assertDontSee('kami-form-errors');
    expect(Submission::query()->count())->toBe(0);
})->with([
    'missing' => fn (Entry $page): string => '',
    'unknown Entry' => fn (Entry $page): string => '01JAAAAAAAAAAAAAAAAAAAAAAA:fr',
    'no Locale' => fn (Entry $page): string => $page->id,
    'a Locale of no Site here' => fn (Entry $page): string => "{$page->id}:de",
    'a Translation not visible' => function (Entry $page): string {
        resolve(SaveEntry::class)->handle(Home::class, ['status' => 'draft'], $page, 'en');

        return "{$page->id}:en";
    },
]);

it('answers 404 for a Form that is not declared', function () {
    test()->withHeaders(['Sec-Fetch-Site' => 'same-origin'])->post('/kami/forms/newsletter')->assertNotFound();
});

it('throttles five Submissions per minute per IP and Form, an IPv6 address by its /64, before validating', function () {
    $page = contactPage();
    $from = fn (string $ip, array $values = []) => test()->withServerVariables(['REMOTE_ADDR' => $ip])->withHeaders(['Sec-Fetch-Site' => 'same-origin'])
        ->post('/kami/forms/contact', ['_kami_origin' => "{$page->id}:fr", 'name' => 'Ada', 'email' => 'ada@example.com', 'message' => 'Hello, I would like to talk.', ...$values]);

    foreach (range(1, 4) as $ignored) {
        $from('2001:db8:1:1::1')->assertStatus(303);
    }
    $from('2001:db8:1:1:ffff::2', ['message' => ''])->assertUnprocessable();

    $from('2001:db8:1:1::3')->assertTooManyRequests()->assertHeader('Retry-After');
    $from('2001:db8:1:2::1')->assertStatus(303);
    $from('192.0.2.1')->assertStatus(303);
    $from('::ffff:198.51.100.7')->assertStatus(303);
    $this->travel(61)->seconds();
    $from('2001:db8:1:1::1')->assertStatus(303);
    expect(Submission::query()->count())->toBe(8);
});

it('re-renders the page as a 422 with the errors, the input and no cookie, never stored or cached', function () {
    $page = contactPage();

    $response = sendContact($page, ['name' => 'Ada <b>Lovelace</b>', 'message' => 'Hi', 'password' => 'secret']);

    $response->assertUnprocessable()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertSee('<div class="kami-form-errors" role="alert" tabindex="-1" autofocus>', escape: false)
        ->assertSee('<h2 class="kami-form-errors-heading">Il y a un problème</h2>', escape: false)
        ->assertSee('<a href="#message">', escape: false)
        ->assertSee('value="Ada &lt;b&gt;Lovelace&lt;/b&gt;"', escape: false)
        ->assertSee('aria-describedby="message-error" >Hi</textarea>', escape: false)
        ->assertDontSee('secret')
        ->assertDontSee('name="_token"', escape: false);
    expect($response->headers->getCookies())->toBe([])
        ->and(Submission::query()->count())->toBe(0);
});

it('renders the form with the page, its honeypot and origin, and the confirmation on ?sent=', function () {
    $page = contactPage();

    $this->get('/en')->assertOk()
        ->assertSee('<form method="post" action="/kami/forms/contact" id="contact" class="kami-form">', escape: false)
        ->assertSee("name=\"_kami_origin\" value=\"{$page->id}:en\"", escape: false)
        ->assertSee('<div class="kami-form-honeypot" hidden>', escape: false)
        ->assertSee('name="kami_website" tabindex="-1" autocomplete="off"', escape: false)
        ->assertDontSee('name="_token"', escape: false)
        ->assertDontSee('kami-form-errors');
    $this->get('/en?sent=contact')->assertOk()->assertSee('<p id="contact" role="status">', escape: false)->assertDontSee('<form', escape: false);
    $this->get('/en?sent=other')->assertOk()->assertSee('<form', escape: false);
});

it('emits @csrf under Kami::routes(session: true), whose form route joins the web group', function () {
    Kami::routes(session: true);
    contactPage();

    $response = $this->get('/en');

    $response->assertOk()->assertSee('name="_token"', escape: false);
    // Laravel skips its CSRF check while running tests, so the group that holds it stands in for a 419.
    expect(Route::getRoutes()->getByName('kami.form')?->gatherMiddleware())->toContain('web');
});

it('renders the page that holds a Form in an exact number of queries', function () {
    contactPage();

    DB::enableQueryLog();
    $this->get('/en')->assertOk()->assertSee('kami-form');

    // The page's own, none of the Form's: the Path, the Entry with its visibility, its Translations, its type row, the Site settings, the Social image Reference.
    expect(DB::getQueryLog())->toHaveCount(6);
});

it('adds no query to the page that holds a Form', function () {
    $page = contactPage();
    request()->attributes->set('kami.entry', $page);

    DB::enableQueryLog();
    $html = Blade::render('<x-kami::form form="contact"><x-kami::form.errors /></x-kami::form>');

    expect(DB::getQueryLog())->toBe([])->and($html)->toContain('kami-form');
});

it('re-renders the input and errors from the visitor\'s session under Kami::routes(session: true)', function () {
    Kami::routes(session: true);
    $page = contactPage();

    $response = sendContact($page, ['name' => '']);

    $response->assertUnprocessable()->assertSee('<a href="#name">', escape: false)->assertSee('value="ada@example.com"', escape: false);
    $this->get('/')->assertOk()->assertDontSee('value="ada@example.com"', escape: false)->assertDontSee('kami-form-errors');
});

it('derives a Form\'s handle from its class name without a trailing Form, and labels it from kami-forms', function () {
    expect(new ContactForm()->handle())->toBe('contact')
        ->and(new QuoteRequest()->handle())->toBe('quote_request')
        ->and(new ContactFormV2()->handle())->toBe('contact_form_v2')
        ->and(Kami::form('contact')->label())->toBe('Contact');
});

it('validates kami.forms when a Form is first read', function (array $forms, string $message) {
    config(['kami.forms' => $forms]);

    expect(fn () => Kami::form('contact'))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'not a Form' => [[Home::class], 'Every entry of kami.forms is a class extending Psyao\Kami\Forms\Form.'],
    'a handle twice' => [[ContactForm::class, ContactForm::class], 'the handle "contact" is already the handle of Workbench\App\Forms\ContactForm'],
    'an undeclared handle' => [[], 'No Form [contact] is listed under kami.forms.'],
]);

it('stores the validated keys of rules() only, a nested rule keeping its parent key', function () {
    $form = new class extends Form
    {
        public function handle(): string
        {
            return 'survey';
        }

        public function rules(): array
        {
            return ['topics' => ['array'], 'topics.*' => ['string'], 'answer.text' => ['string']];
        }
    };

    $submission = resolve(StoreSubmission::class)->handle($form, ['topics' => ['a'], 'answer' => ['text' => 'b'], 'ip' => '192.0.2.1'], 'fr');

    expect($submission->refresh()->data)->toEqual(['topics' => ['a'], 'answer' => ['text' => 'b']])
        ->and($submission->entry_id)->toBeNull()
        ->and($form->summary($submission))->toBe('')
        ->and($form->retention()?->totalMonths)->toBe(12.0)
        ->and(fn () => resolve(StoreSubmission::class)->handle($form, [], 'de'))->toThrow(InvalidArgumentException::class, 'The Locale [de] is not one of the Site\'s.');
});

it('summarises a Submission by its first text value in the order of rules(), truncated', function () {
    $form = Kami::form('contact');

    expect($form->summary(new Submission(['data' => ['message' => 'Hello there', 'email' => 'ada@example.com', 'name' => '  ']])))->toBe('ada@example.com')
        ->and($form->summary(new Submission(['data' => ['message' => str_repeat('word ', 30)]])))->toBe(trim(str_repeat('word ', 20)) . '...');
});
