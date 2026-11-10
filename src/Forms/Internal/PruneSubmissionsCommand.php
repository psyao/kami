<?php

declare(strict_types=1);

namespace Psyao\Kami\Forms\Internal;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Psyao\Kami\Forms\Submission;

/**
 * Deletes for good, daily in `Kami::schedule()`, the Submissions older than their Form's `retention()`, their deliveries through the
 * foreign key's cascade, so a site keeps no visitor's message longer than it says (ADR 0015). A Form no longer declared has no retention
 * to read: its Submissions are kept, and `kami:doctor` reports them. Retention is a policy, not an actor's deletion: no Audit event and
 * no `SubmissionsDeleted`, unlike `DeleteSubmissions`. In chunks, each its own statement: a run cut short is simply started again.
 *
 * @internal
 */
#[Description("Delete the Form Submissions older than their Form's retention")]
#[Signature('kami:forms:prune')]
final class PruneSubmissionsCommand extends Command
{
    private const int CHUNK = 1000;

    public function handle(Forms $forms): int
    {
        $now = now()->toImmutable();
        $status = self::SUCCESS;

        foreach ($forms->all() as $handle => $form) {
            if (($retention = $form->retention()) === null) {
                $this->components->info("{$handle}: kept forever.");

                continue;
            }

            // A typo deleting every Submission would erase what a visitor sent a minute ago.
            if (($before = $now->sub($retention)) >= $now) {
                $this->components->error($form::class . '::retention() must be longer than zero, or null to keep forever.');
                $status = self::FAILURE;

                continue;
            }

            $deleted = 0;

            do {
                $chunk = Submission::query()->toBase()->where('form', $handle)->where('created_at', '<', $before)->limit(self::CHUNK)->delete();
                $deleted += $chunk;
            } while ($chunk === self::CHUNK);

            $this->components->info("{$handle}: deleted {$deleted} " . Str::plural('submission', $deleted) . " older than {$retention->forHumans()}.");
        }

        return $status;
    }
}
