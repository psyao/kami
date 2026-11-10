<?php

declare(strict_types=1);

use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Psyao\Kami\Admin\Internal\SubmissionResource;
use Psyao\Kami\Admin\Internal\SubmissionResource\ListSubmissions;
use Psyao\Kami\Admin\Internal\SubmissionResource\ViewSubmission;
use Psyao\Kami\Audit\Internal\AuditEvent;
use Psyao\Kami\Forms\Events\SubmissionsDeleted;
use Psyao\Kami\Forms\Form;
use Psyao\Kami\Forms\Submission;
use Workbench\App\Forms\ContactForm;

uses(RefreshDatabase::class);

/** A second declared Form, so the list has two tabs. */
final class QuoteForm extends Form
{
    public function rules(): array
    {
        return ['company' => ['required', 'string'], 'budget' => ['nullable', 'integer']];
    }
}

beforeEach(function () {
    config(['kami.forms' => [ContactForm::class, QuoteForm::class]]);
});

/**
 * A stored Submission, with one delivery row per channel given as `[attempts, delivered, last_error]`.
 *
 * @param  array<string, array{int, bool, ?string}>  $deliveries
 */
function received(array $data = ['name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'message' => 'Hello there'], string $form = 'contact', array $deliveries = ['mail' => [1, true, null]], string $site = 'default', ?string $at = null, bool $read = false): Submission
{
    $submission = Submission::query()->create(['site' => $site, 'locale' => 'fr', 'form' => $form, 'data' => $data, 'read_at' => $read ? now() : null, 'created_at' => $at ?? now(), 'updated_at' => $at ?? now()]);

    foreach ($deliveries as $channel => [$attempts, $delivered, $error]) {
        DB::table('kami_form_deliveries')->insert(['id' => (string) Str::ulid(), 'submission_id' => $submission->id, 'channel' => $channel, 'attempts' => $attempts, 'delivered_at' => $delivered ? now() : null, 'last_error' => $error, 'created_at' => now(), 'updated_at' => now()]);
    }

    return $submission;
}

/** A page of the Submissions screen, for a User holding the Role, or these Permissions under a Role of their own. */
function submissionsPage(string $page = ListSubmissions::class, string | array $role = 'administrator', array $parameters = []): Testable
{
    signInWith($role);
    Filament::setCurrentPanel('admin');

    return Livewire::test($page, $parameters);
}

it('takes kami.form-submissions.view, which the Workbench editor holds, and kami.form-submissions.delete to delete, which it does not', function () {
    $submission = received();

    signInWith(['kami.media.view']);
    $this->get('/admin/submissions')->assertForbidden();
    $this->get("/admin/submissions/{$submission->id}")->assertForbidden();

    signInWith('editor');
    $this->get('/admin/submissions')->assertOk();
    $this->get("/admin/submissions/{$submission->id}")->assertOk();

    submissionsPage(role: 'editor')->assertTableBulkActionHidden(DeleteBulkAction::class);
    submissionsPage(ViewSubmission::class, 'editor', ['record' => $submission->id])->assertActionHidden(DeleteAction::class);
    submissionsPage(ViewSubmission::class, ['kami.form-submissions.view', 'kami.form-submissions.delete'], ['record' => $submission->id])->assertActionVisible(DeleteAction::class);
});

it('lists a tab per Form, the date in the Site’s time zone, the Locale, the summary and the delivery state, the current Site only', function () {
    config(['kami.sites.default.timezone' => 'Europe/Zurich']);
    $delivered = received(at: '2026-07-01 08:00:00');
    $failed = received(['name' => 'Grace Hopper', 'email' => 'grace@example.com', 'message' => 'Hi'], deliveries: ['mail' => [5, false, 'Connection refused']], at: '2026-07-01 09:00:00');
    $retrying = received(['name' => 'Alan', 'message' => 'Hi'], deliveries: ['mail' => [2, false, 'Timeout']], at: '2026-07-02 08:00:00');
    $pending = received(['name' => 'Edsger', 'message' => 'Hi'], deliveries: ['mail' => [0, false, null], 'chat' => [1, true, null]], at: '2026-07-03 08:00:00');
    $none = received(['name' => 'Barbara', 'message' => 'Hi'], deliveries: [], at: '2026-07-04 08:00:00');
    $quote = received(['company' => 'Acme', 'budget' => 1000], 'quote');
    $elsewhere = received(['name' => 'Blog visitor', 'message' => 'Hi'], site: 'blog');

    submissionsPage()
        ->assertSeeText(['Contact', 'Quote'])
        ->assertCanSeeTableRecords([$none, $pending, $retrying, $failed, $delivered], inOrder: true)
        ->assertCanNotSeeTableRecords([$quote, $elsewhere])
        ->assertTableColumnFormattedStateSet('created_at', 'Jul 1, 2026 10:00:00', $delivered)
        ->assertTableColumnFormattedStateSet('locale', 'FR', $delivered)
        ->assertTableColumnStateSet('summary', 'Ada Lovelace', $delivered)
        ->assertTableColumnFormattedStateSet('delivery', 'Delivered', $delivered)
        ->assertTableColumnFormattedStateSet('delivery', 'Failed', $failed)
        ->assertTableColumnFormattedStateSet('delivery', 'Retrying', $retrying)
        ->assertTableColumnFormattedStateSet('delivery', 'Pending', $pending)
        ->assertTableColumnFormattedStateSet('delivery', 'Nothing to send', $none)
        ->set('activeTab', 'quote')
        ->assertCanSeeTableRecords([$quote])
        ->assertCanNotSeeTableRecords([$delivered])
        ->assertTableColumnStateSet('summary', 'Acme', $quote);
});

it('gives Submissions of a Form no longer declared a tab of their own, by handle, readable and deletable', function () {
    $orphan = received(['zeta' => 'Last', 'alpha' => 'First'], 'newsletter');

    submissionsPage()
        ->assertSeeText('newsletter')
        ->set('activeTab', 'newsletter')
        ->assertCanSeeTableRecords([$orphan])
        ->assertTableColumnStateSet('summary', 'First', $orphan);

    submissionsPage(ViewSubmission::class, parameters: ['record' => $orphan->id])
        ->assertSeeTextInOrder(['Alpha', 'First', 'Zeta', 'Last'])
        ->callAction(DeleteAction::class);

    expect(Submission::query()->count())->toBe(0);
});

it('shows each field with its label in the order of rules(), the visitor’s text escaped, each channel’s delivery, and a reply link', function () {
    $submission = received(['message' => "<script>alert(1)</script>\nSecond line", 'email' => 'ada@example.com', 'name' => 'Ada'], deliveries: ['mail' => [3, false, 'Connection refused'], 'chat' => [1, true, null]]);

    submissionsPage(ViewSubmission::class, parameters: ['record' => $submission->id])
        ->assertSeeTextInOrder(['Name', 'Ada', 'Email address', 'ada@example.com', 'Message'])
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertSeeHtml('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->assertSeeTextInOrder(['chat', 'Delivered', 'mail', 'Retrying', '3', 'Connection refused'])
        ->assertActionHasUrl('reply', 'mailto:ada@example.com');
});

it('offers no reply link when the reply-to field holds no valid address', function () {
    $submission = received(['name' => 'Ada', 'email' => 'not an address', 'message' => 'Hello there']);

    submissionsPage(ViewSubmission::class, parameters: ['record' => $submission->id])->assertActionHidden('reply');
});

it('counts unread Submissions of the current Site in the navigation, and marks one read when opened', function () {
    $unread = received();
    received(['company' => 'Acme'], 'quote');
    received(read: true);
    received(site: 'blog');

    signInWith('editor');
    expect(SubmissionResource::getNavigationBadge())->toBe('2');

    submissionsPage(ViewSubmission::class, 'editor', ['record' => $unread->id]);

    expect($unread->refresh()->read_at)->not->toBeNull()
        ->and(SubmissionResource::getNavigationBadge())->toBe('1');
});

it('deletes Submissions for good, in bulk or one by one, recording each Form and count in the Audit log, never the content', function () {
    $first = received();
    $second = received(['name' => 'Grace', 'message' => 'Hi']);
    $kept = received(['name' => 'Kept', 'message' => 'Hi']);
    $quote = received(['company' => 'Acme'], 'quote');
    Event::fake([SubmissionsDeleted::class]);
    $deleter = signInWith(['kami.form-submissions.view', 'kami.form-submissions.delete']);
    Filament::setCurrentPanel('admin');

    Livewire::test(ListSubmissions::class)->selectTableRecords([$first, $second])->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk());
    Livewire::test(ViewSubmission::class, ['record' => $quote->id])->callAction(DeleteAction::class)->assertRedirect(SubmissionResource::getUrl());

    expect(Submission::query()->pluck('id')->all())->toBe([$kept->id])
        ->and(DB::table('kami_form_deliveries')->pluck('submission_id')->all())->toBe([$kept->id])
        ->and(AuditEvent::query()->where('event', 'kami.form-submissions.deleted')->get()->map->only(['user_id', 'subject_type', 'context'])->sortBy('context.form')->values()->all())->toEqual([
            ['user_id' => $deleter->getKey(), 'subject_type' => null, 'context' => ['form' => 'contact', 'count' => 2]],
            ['user_id' => $deleter->getKey(), 'subject_type' => null, 'context' => ['form' => 'quote', 'count' => 1]],
        ])
        ->and(AuditEvent::query()->pluck('context')->toJson())->not->toContain('Ada')->not->toContain('Acme');
    Event::assertDispatched(SubmissionsDeleted::class, fn (SubmissionsDeleted $event): bool => $event->form === 'contact' && collect($event->submissionIds)->sort()->values()->all() === collect([$first->id, $second->id])->sort()->values()->all() && $event->actor?->is($deleter));
    Event::assertDispatched(SubmissionsDeleted::class, fn (SubmissionsDeleted $event): bool => $event->form === 'quote' && $event->submissionIds === [$quote->id]);
});

it('is not in the admin when no Form is declared', function () {
    $this->bootConfig = ['kami.forms' => []];
    $this->refreshApplication();

    expect(Filament::getPanel('admin')->getResources())->not->toContain(SubmissionResource::class)
        ->and(Route::has('filament.admin.resources.submissions.index'))->toBeFalse();
});
