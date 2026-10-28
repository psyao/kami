<?php

declare(strict_types=1);

namespace Psyao\Kami\Users\Internal;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Psyao\Kami\Content\Entry;

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

    /** The Translation the Entry is read in: changing a visible one changes what visitors see, so it takes `publish` too (ADR 0019). */
    public function update(Authenticatable $user, Entry $entry): bool
    {
        return $this->can($user, $entry->type, 'update') && (! $entry->isVisible() || $this->can($user, $entry->type, 'publish'));
    }

    /** Making a Translation visible, or keeping it so, on an Entry or on one being created. */
    public function publish(Authenticatable $user, Entry | string $entry): bool
    {
        return $this->can($user, $entry instanceof Entry ? $entry->type : $entry, 'publish');
    }

    private function can(Authenticatable $user, string $type, string $action): bool
    {
        return Gate::forUser($user)->allows("kami.entries.{$type}.{$action}");
    }
}
