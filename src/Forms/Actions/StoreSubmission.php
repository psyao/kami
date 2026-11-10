<?php

declare(strict_types=1);

namespace Psyao\Kami\Forms\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Forms\Events\SubmissionStored;
use Psyao\Kami\Forms\Form;
use Psyao\Kami\Forms\Internal\Forms;
use Psyao\Kami\Forms\Submission;
use Psyao\Kami\Sites\Internal\Site;

/**
 * Stores a Submission of the current Site, then dispatches `SubmissionStored` after commit (ADR 0011, ADR 0029). It neither validates nor checks
 * the visitor: the form route does, before it. No Content version bump and no Audit event: a Submission is not Content.
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
        $form = is_string($form) ? resolve(Forms::class)->find($form) ?? throw new InvalidArgumentException("No Form [{$form}] is listed under kami.forms.") : $form;
        $site = Site::current();

        if (! in_array($locale, $site->locales, true)) {
            throw new InvalidArgumentException("The Locale [{$locale}] is not one of the Site's.");
        }

        $submission = DB::transaction(fn (): Submission => Submission::query()->create([
            'site' => $site->handle,
            'locale' => $locale,
            'form' => $form->handle(),
            'entry_id' => $entry?->id,
            'data' => array_intersect_key($data, array_flip(array_map(fn (string $key): string => Str::before($key, '.'), array_keys($form->rules())))),
        ]));

        event(new SubmissionStored($submission));

        return $submission;
    }
}
