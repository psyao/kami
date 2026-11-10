<?php

declare(strict_types=1);

namespace Psyao\Kami\Forms\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Psyao\Kami\Audit\Actions\RecordAuditEvent;
use Psyao\Kami\Forms\Events\SubmissionsDeleted;
use Psyao\Kami\Forms\Submission;

/**
 * Deletes Submissions for good, their deliveries with them, as a visitor's deletion request asks, and records one `kami.form-submissions.deleted` per Form in the Audit log with the count deleted, never the content (ADR 0022); `SubmissionsDeleted` follows per Form once committed. It does not authorise: the caller checks the actor's `kami.form-submissions.delete` Permission first (ADR 0011).
 */
final class DeleteSubmissions
{
    /**
     * @param  iterable<int, Submission>  $submissions
     * @param  Authenticatable|null  $actor  Null for the CLI.
     */
    public function handle(iterable $submissions, ?Authenticatable $actor = null): void
    {
        $groups = [];

        foreach ($submissions as $submission) {
            $groups["{$submission->site}:{$submission->form}"][] = $submission->id;
        }

        DB::transaction(function () use ($groups, $actor): void {
            foreach ($groups as $group => $ids) {
                [$site, $form] = explode(':', $group, 2);
                // Those still there, locked: one removed meanwhile is neither counted nor announced twice.
                $ids = array_values(Submission::query()->whereIn('id', $ids)->lockForUpdate()->get(['id'])->map(fn (Submission $submission): string => $submission->id)->all());

                if ($ids !== []) {
                    Submission::query()->toBase()->whereIn('id', $ids)->delete();
                    resolve(RecordAuditEvent::class)->handle('kami.form-submissions.deleted', null, ['form' => $form, 'count' => count($ids)], $actor);
                    event(new SubmissionsDeleted($form, $site, $ids, $actor));
                }
            }
        });
    }
}
