<?php

declare(strict_types=1);

namespace Psyao\Kami\Forms\Internal;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Forms\Form;

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
