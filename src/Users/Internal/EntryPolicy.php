<?php

declare(strict_types=1);

namespace Psyao\Kami\Users\Internal;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Internal\Translation;

/**
 * Filament's policy methods mapped to the Entry's type Permissions (ADR 0014). Without a record, Filament passes the type's handle: `[Entry::class, 'article']`.
 *
 * @internal
 */
final class EntryPolicy
{
    public function viewAny(Authenticatable $user, string $type): bool
    {
        return $this->can($user, $type, 'view');
    }

    public function view(Authenticatable $user, Entry $entry): bool
    {
        return $this->can($user, $entry->type, 'view');
    }

    public function create(Authenticatable $user, string $type): bool
    {
        return $this->can($user, $type, 'create');
    }

    /** Duplicating creates an Entry, a Draft in every Locale. */
    public function replicate(Authenticatable $user, Entry $entry): bool
    {
        return $this->can($user, $entry->type, 'create');
    }

    public function reorder(Authenticatable $user, string $type): bool
    {
        return $this->can($user, $type, 'reorder');
    }

    /** The Translation the Entry is read in: changing a Published or Scheduled one changes what visitors see, so it takes `publish` too (ADR 0019). */
    public function update(Authenticatable $user, Entry $entry): bool
    {
        return $this->can($user, $entry->type, 'update') && ($entry->status !== Translation::PUBLISHED || $this->can($user, $entry->type, 'publish'));
    }

    /** Making a Translation visible, or keeping it so, on an Entry or on one being created. */
    public function publish(Authenticatable $user, Entry | string $entry): bool
    {
        return $this->can($user, $entry instanceof Entry ? $entry->type : $entry, 'publish');
    }

    /** Moving it to the Trash, in all its Locales. */
    public function delete(Authenticatable $user, Entry $entry): bool
    {
        return $this->can($user, $entry->type, 'delete');
    }

    /** Bulk actions: each row is then authorised on its own, through the method below. */
    public function deleteAny(Authenticatable $user, string $type): bool
    {
        return $this->can($user, $type, 'delete');
    }

    public function restoreAny(Authenticatable $user, string $type): bool
    {
        return $this->can($user, $type, 'restore');
    }

    public function forceDeleteAny(Authenticatable $user, string $type): bool
    {
        return $this->can($user, $type, 'force-delete');
    }

    /** A published Translation, Scheduled ones included, is visible again once restored: it takes `publish` too (ADR 0019). */
    public function restore(Authenticatable $user, Entry $entry): bool
    {
        return $this->can($user, $entry->type, 'restore')
            && ($entry->translations->doesntContain('status', Translation::PUBLISHED) || $this->can($user, $entry->type, 'publish'));
    }

    /** Deleting from the Trash for good, its addresses redirected or answering 410 (ADR 0008). */
    public function forceDelete(Authenticatable $user, Entry $entry): bool
    {
        return $this->can($user, $entry->type, 'force-delete');
    }

    /** Creating, listing and revoking the Preview links of a Translation (ADR 0019). */
    public function sharePreview(Authenticatable $user, Entry $entry): bool
    {
        return $this->can($user, $entry->type, 'share-preview');
    }

    private function can(Authenticatable $user, string $type, string $action): bool
    {
        return Gate::forUser($user)->allows("kami.entries.{$type}.{$action}");
    }
}
