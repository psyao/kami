<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Facades\Kami;
use Psyao\Kami\Forms\Actions\StoreSubmission;
use Psyao\Kami\Forms\Form;
use Psyao\Kami\Forms\Internal\Deliveries;
use Psyao\Kami\Forms\Internal\Delivery;
use Psyao\Kami\Forms\Submission;
use Psyao\Kami\Scheduling\Internal\Tick;
use Psyao\Kami\Support\Internal\Install\PluginRegistration;
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

/** A channel each of whose sends takes 15 seconds. */
final class SlowChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        test()->travel(15)->seconds();
    }
}

/** A channel whose first send answers after two minutes, while a Tick runs `kami:forms:deliver` beside it. */
final class HangingChannel
{
    public static int $sends = 0;

    public function send(object $notifiable, Notification $notification): void
    {
        if (self::$sends++ === 0) {
            test()->travel(2)->minutes();
            Artisan::call('kami:forms:deliver');
        }
    }
}

/** A Form whose notification goes through the hanging channel only. */
final class HangingForm extends Form
{
    public function rules(): array
    {
        return ['message' => ['required', 'string']];
    }

    public function notification(Submission $submission): Notification
    {
        return new class extends Notification
        {
            public function via(object $notifiable): array
            {
                return [HangingChannel::class];
            }
        };
    }
}

/** A Form whose notification goes through the slow channel only. */
final class SlowForm extends Form
{
    public function rules(): array
    {
        return ['message' => ['required', 'string']];
    }

    public function notification(Submission $submission): Notification
    {
        return new class extends Notification
        {
            public function via(object $notifiable): array
            {
                return [SlowChannel::class];
            }
        };
    }
}

/** A Form with two fields a site labels the same. */
final class PhonesForm extends Form
{
    public function rules(): array
    {
        return ['phone' => ['string'], 'mobile' => ['string']];
    }

    public function recipients(): array
    {
        return ['owner@example.com'];
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
        ->and($mail->getTextBody())->toBe("A visitor sent the Contact form.\n\nName:\nAda Lovelace\n\nEmail address:\nada@example.com\n\nMessage:\nHello,\nI would like to talk.\n\nOpen it in the admin: " . config('app.url') . '/admin/submissions/' . Submission::query()->sole()->id . "\n");
    $delivery = DB::table('kami_form_deliveries')->sole();
    expect($delivery->attempts)->toBe(1)->and($delivery->delivered_at)->not->toBeNull()->and($delivery->last_error)->toBeNull();
});

it('delivers each channel on its own once the response is sent, one failing never blocking the other', function () {
    Exceptions::fake();
    $home = resolve(SaveEntry::class)->handle(Home::class, ['title' => 'Accueil', 'status' => 'published'], locale: 'fr');

    $this->withHeaders(['Sec-Fetch-Site' => 'same-origin'])->post('/kami/forms/chat', ['_kami_origin' => "{$home->id}:fr", 'message' => 'Hi'])->assertStatus(303);

    $submission = Submission::query()->sole();
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

it('retries at once what failed or never ran from kami:forms:deliver, abandoning a delivery after five attempts', function () {
    Exceptions::fake();
    $this->freezeTime();
    $fresh = storedSubmission();
    $failed = storedSubmission();
    $abandoned = storedSubmission();
    DB::table('kami_form_deliveries')->where('submission_id', $failed->id)->update(['attempts' => 4, 'last_error' => 'Connection refused']);
    DB::table('kami_form_deliveries')->where('submission_id', $abandoned->id)->update(['attempts' => 5, 'last_error' => 'Connection refused']);

    $this->artisan('kami:forms:deliver')->expectsOutputToContain('Delivered 2 of 2 pending deliveries.')->assertSuccessful();

    // From the console too, the mail links to the admin.
    expect(sentMails())->toHaveCount(2)->and(sentMails()->first()->getTextBody())->toContain("/admin/submissions/{$fresh->id}");
    $this->assertDatabaseHas('kami_form_deliveries', ['submission_id' => $failed->id, 'attempts' => 5, 'last_error' => null]);
    $this->assertDatabaseHas('kami_form_deliveries', ['submission_id' => $fresh->id, 'attempts' => 1]);
    $this->assertDatabaseHas('kami_form_deliveries', ['submission_id' => $abandoned->id, 'attempts' => 5, 'delivered_at' => null]);
});

it('leaves a delivery being sent elsewhere until its claim is an hour old, as when PHP stopped mid-send', function () {
    $this->freezeTime();
    $delivery = DB::table('kami_form_deliveries')->where('submission_id', storedSubmission()->id);
    $delivery->update(['attempts' => 1, 'updated_at' => now()->subMinutes(59)]);

    $this->artisan('kami:forms:deliver')->expectsOutputToContain('Delivered 0 of 0 pending deliveries.')->assertSuccessful();

    $delivery->update(['updated_at' => now()->subHour()]);
    $this->artisan('kami:forms:deliver')->expectsOutputToContain('Delivered 1 of 1 pending delivery.')->assertSuccessful();
    expect(sentMails())->toHaveCount(1);
});

it('sends once a Submission whose deferred send outlasts a minute while kami:forms:deliver runs beside it', function () {
    config(['kami.forms' => [HangingForm::class]]);
    HangingChannel::$sends = 0;
    $this->travelTo('2026-11-09 10:00:00');

    storedSubmission('hanging', ['message' => 'Hi']);
    deferred();

    expect(HangingChannel::$sends)->toBe(1);
    $this->assertDatabaseHas('kami_form_deliveries', ['attempts' => 1, 'last_error' => null]);
    $this->assertDatabaseMissing('kami_form_deliveries', ['delivered_at' => null]);
});

it('lets one sender only claim a delivery two have read', function () {
    $submission = storedSubmission();
    [$first, $second] = [Delivery::query()->sole(), Delivery::query()->sole()];

    expect(Deliveries::send($first->setRelation('submission', $submission)))->toBeTrue()
        ->and(Deliveries::send($second->setRelation('submission', $submission)))->toBeNull()
        ->and(sentMails())->toHaveCount(1);
    $this->assertDatabaseHas('kami_form_deliveries', ['attempts' => 1]);
});

it('names the failed deliveries waiting for their next try, and when', function () {
    Exceptions::fake();
    config(['kami.forms' => [ChatForm::class]]);
    Kami::schedule();
    $this->travelTo('2026-11-09 17:32:00');
    storedSubmission('chat', ['message' => 'Hi']);
    deferred();

    $this->artisan('kami:forms:deliver')->expectsOutputToContain('Delivered 0 of 1 pending delivery. 1 waiting for its next try at 17:45.')->assertSuccessful();
});

it('starts no delivery past the remaining budget of the Tick that runs kami:forms:deliver', function () {
    $this->app->useStoragePath($storage = temporaryPath());
    config(['kami.forms' => [SlowForm::class]]);
    resolve(Schedule::class)->command('kami:forms:deliver')->everyMinute();
    foreach (range(1, 3) as $n) {
        storedSubmission('slow', ['message' => "Hi {$n}"]);
    }
    $this->travelTo('2026-11-09 10:00:00');
    Tick::run('cli', 10);

    $this->travelTo('2026-11-09 10:05:00');

    try {
        expect(Tick::run('cli', 10))->toBe('OK')
            ->and(DB::table('kami_form_deliveries')->whereNotNull('delivered_at')->count())->toBe(1);
    } finally {
        File::deleteDirectory($storage);
    }
});

it('starts no delivery once kami:forms:deliver has spent 20 seconds, leaving the rest to the next run', function () {
    config(['kami.forms' => [SlowForm::class]]);
    foreach (range(1, 3) as $n) {
        storedSubmission('slow', ['message' => "Hi {$n}"]);
    }

    $this->artisan('kami:forms:deliver')->expectsOutputToContain('Delivered 2 of 2 pending deliveries. Out of time: the rest waits for the next run.')->assertSuccessful();

    expect(DB::table('kami_form_deliveries')->whereNotNull('delivered_at')->count())->toBe(2);
});

it('still mails a Submission when the admin link cannot be built', function () {
    Exceptions::fake();
    resolve(PluginRegistration::class)->submissionUrl = fn (): string => throw new RuntimeException('No panel');

    storedSubmission();
    deferred();

    expect(sentMails()->sole()->getTextBody())->not->toContain('Open it in the admin');
    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'No panel');
});

it('lists two fields sharing a label each on its own line', function () {
    Lang::addLines(['validation.attributes.phone' => 'Phone', 'validation.attributes.mobile' => 'Phone'], 'en');
    config(['kami.forms' => [PhonesForm::class]]);

    storedSubmission('phones', ['phone' => '021 000 00 00', 'mobile' => '079 000 00 00']);
    deferred();

    expect(sentMails()->sole()->getTextBody())->toContain("Phone:\n021 000 00 00\n\nPhone:\n079 000 00 00");
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

it('calls a delivery abandoned, in a query and on a loaded model alike, once undelivered after five attempts, and pending until then', function () {
    $deliveries = collect([[0, null], [4, null], [5, null], [6, null], [5, now()]])->map(function (array $state): Delivery {
        [$attempts, $deliveredAt] = $state;
        $delivery = Delivery::query()->where('submission_id', storedSubmission()->id)->sole();
        $delivery->update(['attempts' => $attempts, 'delivered_at' => $deliveredAt]);

        return $delivery;
    });

    expect(Delivery::query()->abandoned()->pluck('attempts')->sort()->values()->all())->toBe([5, 6])
        ->and(Delivery::query()->pending()->pluck('attempts')->sort()->values()->all())->toBe([0, 4])
        ->and($deliveries->filter->isAbandoned()->pluck('attempts')->values()->all())->toBe([5, 6]);
});
