<?php

declare(strict_types=1);

namespace Psyao\Kami\Forms\Actions;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Forms\Events\SubmissionStored;
use Psyao\Kami\Forms\Form;
use Psyao\Kami\Forms\Internal\Deliveries;
use Psyao\Kami\Forms\Internal\Forms;
use Psyao\Kami\Forms\Submission;
use Psyao\Kami\Kami;
use Psyao\Kami\Sites\Internal\Site;

/**
 * Stores a Submission of the current Site with a pending delivery per channel of its Form's notification, dispatches `SubmissionStored`
 * after commit (ADR 0011, ADR 0029), and sends the notification after the response. It neither validates nor checks the visitor: `SubmitForm`
 * does, before it. No Content version bump and no Audit event: a Submission is not Content.
 */
final class StoreSubmission
{
    /**
     * @param  Form|string  $form  The declared Form, or its handle.
     * @param  array<mixed>  $data  The validated values, keyed by the Form's `rules()`; any other key is dropped, and `items.*` keeps `items`.
     * @param  string  $locale  One of the current site's Locales.
     * @param  Entry|null  $entry  The page it was sent from.
     */
    public function handle(Form | string $form, array $data, string $locale, ?Entry $entry = null): Submission
    {
        $form = is_string($form) ? resolve(Kami::class)->form($form) : $form;
        $site = Site::current();

        if (! in_array($locale, $site->locales, true)) {
            throw new InvalidArgumentException("The Locale [{$locale}] is not one of the Site's.");
        }

        $submission = DB::transaction(function () use ($form, $site, $locale, $entry, $data): Submission {
            $submission = Submission::query()->create([
                'site' => $site->handle,
                'locale' => $locale,
                'form' => $form->handle(),
                'entry_id' => $entry?->id,
                'data' => array_intersect_key($data, array_flip(Forms::fields($form))),
            ]);
            Deliveries::prepare($form, $submission);

            return $submission;
        });

        event(new SubmissionStored($submission));
        // After the response, as the visitor waits for nothing; `kami:forms:deliver` retries what fails or never runs.
        defer(fn () => Deliveries::sendPending($submission));

        return $submission;
    }
}
