<?php

declare(strict_types=1);

namespace Psyao\Kami\Forms;

use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

/**
 * A front form a site declares under `kami.forms`: its handle, Laravel rules whose keys are its fields, and what becomes of a Submission (ADR 0015).
 * The site writes its own inputs inside `<x-kami::form>`; Kami renders no field.
 */
abstract class Form
{
    /** `ContactForm` → `contact`: the class name in snake_case, without a trailing `Form`. Used in the route, `form="…"` and `?sent=`. */
    public function handle(): string
    {
        return Str::snake(Str::replaceEnd('Form', '', class_basename(static::class)) ?: class_basename(static::class));
    }

    /**
     * Laravel validation rules; their keys are the fields, and only their validated values are stored. Field labels come from `validation.attributes`.
     *
     * @return array<string, mixed>
     */
    abstract public function rules(): array;

    /**
     * The mail addresses a Submission is sent to.
     *
     * @return list<string>
     */
    public function recipients(): array
    {
        return [];
    }

    /** The field holding the visitor's mail address, put on `Reply-To`; `null` for none. */
    public function replyTo(): ?string
    {
        return null;
    }

    /** One line naming a Submission: by default its first text value in the order of `rules()`, truncated. */
    public function summary(Submission $submission): string
    {
        // In the order of `rules()`: MySQL's json column reorders an object's keys.
        $text = collect(array_keys($this->rules()))->map(fn (string $key): mixed => $submission->data[Str::before($key, '.')] ?? null)
            ->first(fn (mixed $value): bool => is_string($value) && trim($value) !== '');

        return Str::limit(Str::squish(is_string($text) ? $text : ''), 100);
    }

    /** How long a Submission is kept: twelve months by default, `null` forever. */
    public function retention(): ?CarbonInterval
    {
        return CarbonInterval::months(12);
    }

    /** `kami-forms.{handle}.label`, or the humanised handle. */
    final public function label(): string
    {
        $key = "kami-forms.{$this->handle()}.label";

        return Lang::has($key) ? (string) __($key) : Str::headline($this->handle());
    }

    /** Whether the current request is the confirmation after this Form's Submission: `?sent={handle}`. */
    final public function sent(): bool
    {
        return request()->query('sent') === $this->handle();
    }
}
