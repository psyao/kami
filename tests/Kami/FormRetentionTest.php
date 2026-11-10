<?php

declare(strict_types=1);

use Carbon\CarbonInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Psyao\Kami\Forms\Form;
use Psyao\Kami\Forms\Submission;
use Workbench\App\Forms\ContactForm;

uses(RefreshDatabase::class);

/** A Form whose Submissions are kept a week. */
final class WeekForm extends Form
{
    public function rules(): array
    {
        return ['message' => ['required', 'string']];
    }

    public function retention(): ?CarbonInterval
    {
        return CarbonInterval::week();
    }
}

/** A Form whose Submissions are kept forever. */
final class ForeverForm extends Form
{
    public function rules(): array
    {
        return ['message' => ['required', 'string']];
    }

    public function retention(): ?CarbonInterval
    {
        return null;
    }
}

/** A Form whose retention is a typo. */
final class ZeroForm extends Form
{
    public static ?CarbonInterval $retention = null;

    public function rules(): array
    {
        return ['message' => ['required', 'string']];
    }

    public function retention(): ?CarbonInterval
    {
        return self::$retention;
    }
}

function storedAt(string $form, DateTimeInterface $at): Submission
{
    return Submission::query()->create(['site' => 'default', 'locale' => 'en', 'form' => $form, 'data' => ['message' => 'Hello'], 'created_at' => $at, 'updated_at' => $at]);
}

/** @return list<string> */
function keptSubmissions(): array
{
    return Submission::query()->orderBy('id')->pluck('id')->all();
}

it('deletes the Submissions older than twelve months by default, with their deliveries, and keeps the others', function (): void {
    $now = $this->freezeSecond()->toImmutable();
    config(['kami.forms' => [ContactForm::class]]);
    $kept = storedAt('contact', $now->subMonths(12));
    $pruned = storedAt('contact', $now->subMonths(12)->subSecond());
    DB::table('kami_form_deliveries')->insert(['id' => (string) Str::ulid(), 'submission_id' => $pruned->id, 'channel' => 'mail', 'attempts' => 0, 'created_at' => $now, 'updated_at' => $now]);

    $this->artisan('kami:forms:prune')->expectsOutputToContain('contact: deleted 1 submission older than 12 months.')->assertSuccessful();

    expect(keptSubmissions())->toBe([$kept->id])
        ->and(DB::table('kami_form_deliveries')->count())->toBe(0);
});

it("keeps each Form's Submissions for its own retention", function (): void {
    $now = $this->freezeSecond()->toImmutable();
    // Artisan output stays in English, whatever the Admin language Carbon follows.
    app()->setLocale('fr');
    config(['kami.forms' => [ContactForm::class, WeekForm::class]]);
    $contact = storedAt('contact', $now->subMonth());
    storedAt('week', $now->subWeek()->subSecond());
    $week = storedAt('week', $now->subWeek());

    $this->artisan('kami:forms:prune')->expectsOutputToContain('week: deleted 1 submission older than 1 week.')->assertSuccessful();

    expect(keptSubmissions())->toBe([$contact->id, $week->id]);
});

it('deletes more than one chunk', function (): void {
    $now = $this->freezeSecond()->toImmutable();
    config(['kami.forms' => [WeekForm::class]]);
    $at = $now->subMonth()->toDateTimeString();
    DB::table('kami_form_submissions')->insert(array_map(fn (): array => ['id' => (string) Str::ulid(), 'site' => 'default', 'locale' => 'en', 'form' => 'week', 'data' => '{}', 'created_at' => $at, 'updated_at' => $at], range(1, 1001)));

    $this->artisan('kami:forms:prune')->expectsOutputToContain('week: deleted 1001 submissions older than 1 week.')->assertSuccessful();

    expect(keptSubmissions())->toBe([]);
});

it('keeps forever the Submissions of a Form whose retention is null', function (): void {
    $now = $this->freezeSecond()->toImmutable();
    config(['kami.forms' => [ForeverForm::class]]);
    $old = storedAt('forever', $now->subYears(10));

    $this->artisan('kami:forms:prune')->expectsOutputToContain('forever: kept forever.')->assertSuccessful();

    expect(keptSubmissions())->toBe([$old->id]);
});

it('keeps the Submissions of a Form no longer declared', function (): void {
    $now = $this->freezeSecond()->toImmutable();
    config(['kami.forms' => [ContactForm::class]]);
    $orphan = storedAt('quote', $now->subYears(2));

    $this->artisan('kami:forms:prune')->assertSuccessful();

    expect(keptSubmissions())->toBe([$orphan->id]);
});

it('refuses a retention of zero or less, deleting none of its Submissions, and still prunes the other Forms', function (CarbonInterval $retention): void {
    ZeroForm::$retention = $retention;
    $now = $this->freezeSecond()->toImmutable();
    config(['kami.forms' => [ZeroForm::class, WeekForm::class]]);
    $zero = storedAt('zero', $now->subYear());
    storedAt('week', $now->subMonth());

    $this->artisan('kami:forms:prune')
        ->expectsOutputToContain('ZeroForm::retention() must be longer than zero, or null to keep forever.')
        ->expectsOutputToContain('week: deleted 1 submission older than 1 week.')
        ->assertFailed();

    expect(keptSubmissions())->toBe([$zero->id]);
})->with([
    'zero' => [CarbonInterval::days(0)],
    'negative' => [CarbonInterval::days(-1)],
]);
