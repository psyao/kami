<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * One row of a type's `kami_type_<handle>` table: the Shared values (`'*'`) or one Locale's (ADR 0006).
 *
 * @internal
 *
 * @property string $entry_id
 * @property string $locale
 */
#[WithoutTimestamps]
final class FieldRow extends Model
{
    public static function of(string $handle): self
    {
        return (new self)->setTable("kami_type_{$handle}");
    }
}
