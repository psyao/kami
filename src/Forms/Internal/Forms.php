<?php

declare(strict_types=1);

namespace Psyao\Kami\Forms\Internal;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Forms\Form;
use Psyao\Kami\Forms\Submission;

/**
 * The Forms listed in `config/kami.php`, found by handle and validated when first read, never at boot, as Content Types are (ADR 0007).
 *
 * @internal
 */
final class Forms
{
    /** @var array<mixed>|null The `kami.forms` the registry was built from. */
    private ?array $classes = null;

    /** @var array<string, Form> By handle. */
    private array $forms = [];

    /**
     * The fields of a Form: the top-level keys of its `rules()`, in their order; `topics.*` is `topics`.
     *
     * @return list<string>
     */
    public static function fields(Form $form): array
    {
        return array_values(array_unique(array_map(fn (string $key): string => Str::before($key, '.'), array_keys($form->rules()))));
    }

    /**
     * Each stored value with its `validation.attributes` label, as text: in the order of `rules()`, or sorted by key for a Form no longer
     * declared. Never in stored order, which MySQL's json column changes. Two fields may share a label.
     *
     * @return list<array{label: string, value: string}>
     */
    public static function values(Submission $submission, ?Form $form): array
    {
        $keys = $form instanceof Form ? self::fields($form) : array_map(strval(...), array_keys($submission->data));

        if (! $form instanceof Form) {
            sort($keys);
        }

        $values = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $submission->data)) {
                $label = Lang::has("validation.attributes.{$key}") ? (string) __("validation.attributes.{$key}") : Str::headline($key);
                $values[] = ['label' => Str::ucfirst($label), 'value' => self::text($submission->data[$key])];
            }
        }

        return $values;
    }

    /** The visitor's address from the Form's `replyTo()` field, when it holds a valid one: the route validated it, a site's own code may not have. */
    public static function replyTo(Submission $submission, ?Form $form): ?string
    {
        $field = $form?->replyTo();
        $address = $field === null ? null : ($submission->data[$field] ?? null);

        return is_string($address) && filter_var($address, FILTER_VALIDATE_EMAIL) !== false ? $address : null;
    }

    private static function text(mixed $value): string
    {
        return match (true) {
            is_array($value) => implode(', ', array_map(self::text(...), $value)),
            is_bool($value) => $value ? '✓' : '✗',
            is_scalar($value) => (string) $value,
            default => '',
        };
    }

    public function find(string $handle): ?Form
    {
        return $this->all()[$handle] ?? null;
    }

    /** @return array<string, Form> By handle. */
    public function all(): array
    {
        // Rebuilt only when `kami.forms` changes, as in a test.
        if (($classes = Config::array('kami.forms', [])) === $this->classes) {
            return $this->forms;
        }

        $forms = [];

        foreach ($classes as $class) {
            $form = is_string($class) ? app()->make($class) : null;

            if (! $form instanceof Form) {
                throw new InvalidArgumentException('Every entry of kami.forms is a class extending ' . Form::class . '.');
            }

            resolve(ContentTypes::class)->validateHandle($class, 'the handle', $form->handle());

            if (isset($forms[$form->handle()])) {
                throw new InvalidArgumentException("{$class}: the handle \"{$form->handle()}\" is already the handle of " . $forms[$form->handle()]::class . '; override handle() on one of them.');
            }

            $forms[$form->handle()] = $form;
        }

        [$this->forms, $this->classes] = [$forms, $classes];

        return $forms;
    }
}
