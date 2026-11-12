<?php

declare(strict_types=1);

namespace Psyao\Kami\Import;

/**
 * What one Import run did, as `Import::finish()` returns it.
 */
final readonly class ImportReport
{
    /** How many keys of the run's sources it did not see. */
    public int $unseen;

    /**
     * @internal
     *
     * @param  int  $created  Items imported for the first time.
     * @param  int  $updated  Items rewritten from a changed payload.
     * @param  int  $unchanged  Items skipped, their payload unchanged.
     * @param  list<array{source: string, legacy_id: string, entry_id: string|null, media_id: string|null}>  $unseenKeys  The keys of the sources this run touched that it did not see: items gone from the source, which Kami keeps.
     */
    public function __construct(public int $created, public int $updated, public int $unchanged, public array $unseenKeys)
    {
        $this->unseen = count($unseenKeys);
    }
}
