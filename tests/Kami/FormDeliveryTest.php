<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Facades\Kami;
use Psyao\Kami\Forms\Actions\StoreSubmission;
use Psyao\Kami\Forms\Form;
use Psyao\Kami\Forms\Submission;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Workbench\App\Content\Home;
use Workbench\App\Forms\ContactForm;

uses(RefreshDatabase::class);

/** A channel whose service is down. */
final class DownChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        throw new RuntimeException('kChat is down');
    }
}

/** A Form whose notification goes by mail and through a channel of the site's own. */
final class ChatForm extends Form
{
    public function rules(): array
    {
        return ['message' => ['required', 'string']];
    }

    public function recipients(): array
    {
        return ['owner@example.com'];
    }

    public function notification(Submission $submission): Notification
    {
        return new class extends Notification
        {
            public function via(object $notifiable): array
            {
                return ['mail', DownChannel::class];
            }

            public function toMail(object $notifiable): MailMessage
            {
                return (new MailMessage)->subject('Chat')->line('Hello');
            }
        };
    }
}

/** A Form with no recipient. */
final class OrphanForm extends Form
{
    public function rules(): array
    {
        return ['message' => ['required', 'string']];
    }
}

beforeEach(function () {
    config(['mail.default' => 'array', 'kami.forms' => [ContactForm::class, ChatForm::class, OrphanForm::class]]);
    Kami::routes();
});

/** @return Collection<int, Email> */
function sentMails(): Collection
{
    return collect(Mail::mailer('array')->getSymfonyTransport()->messages())->map(fn ($sent): Email => $sent->getOriginalMessage());
}

function deferred(): void
{
    app(DeferredCallbackCollection::class)->invoke();
}

function storedSubmission(string $form = 'contact', array $data = ['name' => 'Ada', 'email' => 'ada@example.com', 'message' => 'Hello there']): Submission
{
    return resolve(StoreSubmission::class)->handle($form, $data, 'fr');
}

it('mails a Submission to its recipients once the response is sent, in the Admin language, with the visitor on Reply-To', function () {
    $home = resolve(SaveEntry::class)->handle(Home::class, ['title' => 'Accueil', 'status' => 'published'], locale: 'fr');
    $this->withHeaders(['Sec-Fetch-Site' => 'same-origin'])->post('/kami/forms/contact', [
        '_kami_origin' => "{$home->id}:fr",
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'message' => "Hello,\nI would like to talk.",
    ])->assertStatus(303);

    $mail = sentMails()->sole();
    expect($mail->getSubject())->toBe('[Contact] Ada Lovelace')
        ->and(array_map(fn (Address $address): string => $address->getAddress(), $mail->getTo()))->toBe(['hello@example.com'])
        ->and($mail->getReplyTo()[0]->getAddress())->toBe('ada@example.com')
        ->and($mail->getHtmlBody())->toBeNull()
        ->and($mail->getTextBody())->toBe("A visitor sent the Contact form.\n\nName:\nAda Lovelace\n\nEmail address:\nada@example.com\n\nMessage:\nHello,\nI would like to talk.\n\n");
    $delivery = DB::table('kami_form_deliveries')->sole();
    expect($delivery->attempts)->toBe(1)->and($delivery->delivered_at)->not->toBeNull()->and($delivery->last_error)->toBeNull();
});

it('delivers each channel on its own, one failing never blocking the other', function () {
    Exceptions::fake();
    $submission = storedSubmission('chat', ['message' => 'Hi']);

    deferred();

    expect(sentMails())->toHaveCount(1);
    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'kChat is down');
    expect(DB::table('kami_form_deliveries')->where('submission_id', $submission->id)->orderBy('channel')->get(['channel', 'attempts', 'last_error'])->map(fn ($row): array => (array) $row)->all())->toBe([
        ['channel' => DownChannel::class, 'attempts' => 1, 'last_error' => 'RuntimeException: kChat is down'],
        ['channel' => 'mail', 'attempts' => 1, 'last_error' => null],
    ]);
});

it('stores a Submission of a Form with no recipient, mails nothing and logs a warning', function () {
    Log::spy();

    $submission = storedSubmission('orphan', ['message' => 'Hi']);
    deferred();

    $this->assertModelExists($submission);
    $this->assertDatabaseCount('kami_form_deliveries', 0);
    expect(sentMails())->toBeEmpty();
    Log::shouldHaveReceived('warning')->once()->with("The Form [orphan] has no recipient: its Submission [{$submission->id}] is stored but not mailed.");
});

it('retries what failed or never ran from kami:forms:deliver, a minute later, abandoning a delivery after five attempts', function () {
    Exceptions::fake();
    $this->freezeTime();
    $fresh = storedSubmission();
    $failed = storedSubmission();
    $abandoned = storedSubmission();
    DB::table('kami_form_deliveries')->where('submission_id', $failed->id)->update(['attempts' => 4, 'last_error' => 'Connection refused', 'updated_at' => now()->subMinutes(15)]);
    DB::table('kami_form_deliveries')->where('submission_id', $abandoned->id)->update(['attempts' => 5, 'updated_at' => now()->subMinutes(15)]);

    $this->artisan('kami:forms:deliver')->expectsOutputToContain('Delivered 1 of 1 pending delivery.')->assertSuccessful();

    expect(sentMails())->toHaveCount(1);
    $this->assertDatabaseHas('kami_form_deliveries', ['submission_id' => $failed->id, 'attempts' => 5, 'last_error' => null]);
    $this->assertDatabaseHas('kami_form_deliveries', ['submission_id' => $fresh->id, 'attempts' => 0, 'delivered_at' => null]);
    $this->assertDatabaseHas('kami_form_deliveries', ['submission_id' => $abandoned->id, 'attempts' => 5, 'delivered_at' => null]);
});

it('delivers at most 50 Submissions per run of kami:forms:deliver, the oldest first', function () {
    foreach (range(1, 51) as $minutes) {
        $this->travelTo(now()->subMinutes(100 - $minutes));
        storedSubmission(data: ['name' => "Visitor {$minutes}", 'email' => 'ada@example.com', 'message' => 'Hi']);
        $this->travelBack();
    }

    $this->artisan('kami:forms:deliver')->expectsOutputToContain('Delivered 50 of 50 pending deliveries.')->assertSuccessful();

    expect(sentMails()->map->getSubject()->last())->toBe('[Contact] Visitor 50');
    $this->assertDatabaseHas('kami_form_deliveries', ['attempts' => 0, 'delivered_at' => null]);
});
