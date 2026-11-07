<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal\AuditEventResource;

use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Psyao\Kami\Admin\Internal\AuditEventResource;
use Psyao\Kami\Admin\Internal\EntryResource;
use Psyao\Kami\Audit\Internal\AuditEvent;

/** @internal */
final class ListAuditEvents extends ListRecords
{
    #[\Override]
    protected static string $resource = AuditEventResource::class;

    /**
     * The names of the Users this page's events name, as actor or subject, in one query.
     *
     * @return array<array-key, string>
     */
    #[Computed]
    public function names(): array
    {
        $keys = $this->events()
            ->flatMap(fn (AuditEvent $event): array => [$event->user_id, $event->subject_type === 'user' ? $event->subject_id : null])
            ->filter(fn (int | string | null $key): bool => $key !== null)
            ->map(strval(...))
            ->unique()
            ->values()
            ->all();

        /** @var list<string> $keys */
        return EntryResource::userNames($keys);
    }

    /** @return array<string, array{string, ?string}> */
    #[Computed]
    public function subjects(): array
    {
        return AuditEventResource::describeSubjects($this->events(), $this->names());
    }

    /**
     * The event's subject as `[label, url]`; one gone, or out of the reader's reach, by its id.
     *
     * @return array{string, ?string}|null
     */
    public function subject(AuditEvent $event): ?array
    {
        $key = AuditEventResource::subjectKey($event);

        return $key === null ? null : $this->subjects()[$key] ?? [AuditEventResource::subjectLabel((string) $event->subject_type, (string) $event->subject_id), null];
    }

    /** @return Collection<int, AuditEvent> */
    private function events(): Collection
    {
        $records = $this->getTableRecords();

        /** @var Collection<int, AuditEvent> */
        return collect($records instanceof Collection ? $records : $records->items());
    }
}
