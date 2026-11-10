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
        $byForm = [];

        foreach ($submissions as $submission) {
            $byForm[$submission->form][] = $submission->id;
        }

        DB::transaction(function () use ($byForm, $actor): void {
            foreach ($byForm as $form => $ids) {
                // Counted as deleted, so one removed meanwhile is not counted twice.
                $count = Submission::query()->toBase()->whereIn('id', $ids)->delete();

                if ($count > 0) {
                    resolve(RecordAuditEvent::class)->handle('kami.form-submissions.deleted', null, ['form' => (string) $form, 'count' => $count], $actor);
                    event(new SubmissionsDeleted((string) $form, $ids, $actor));
                }
            }
        });
    }
}
