<?php

declare(strict_types=1);

namespace Psyao\Kami\Redirects\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Psyao\Kami\Audit\Actions\RecordAuditEvent;
use Psyao\Kami\Redirects\Events\RedirectDeleted;
use Psyao\Kami\Redirects\Redirect;

/**
 * Deletes a Redirect: its source answers 404 again, or the Entry's Path a later save gives it (ADR 0008). It does not authorise: the caller checks the actor's `kami.redirects.manage` Permission first (ADR 0011).
 */
final class DeleteRedirect
{
    /** @param  Authenticatable|null  $actor  Null for imports and the CLI. */
    public function handle(Redirect $redirect, ?Authenticatable $actor = null): void
    {
        $deleted = DB::transaction(function () use ($redirect, $actor): bool {
            // Read again, locked: another call may have deleted it, or an Entry taken its address, since the caller read it.
            $stored = Redirect::query()->lockForUpdate()->find($redirect->id);

            if (! $stored instanceof Redirect) {
                return false;
            }

            $stored->delete();
            resolve(RecordAuditEvent::class)->handle('kami.redirects.deleted', $stored, ['locale' => $stored->locale, 'source' => mb_substr($stored->path, 0, 255), 'status' => $stored->status], $actor);

            return true;
        });

        if ($deleted) {
            event(new RedirectDeleted($redirect->id, $redirect->site, $redirect->locale, $redirect->path, $actor));
        }
    }
}
