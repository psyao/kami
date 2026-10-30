<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Fields\Field;
use Psyao\Kami\Content\Fields\Select;
use Psyao\Kami\Content\Section;
use Psyao\Kami\Content\Tab;

/**
 * The Content Types listed in `config/kami.php`, found by class or handle, validated when first built (ADR 0007).
 *
 * Not at boot: an invalid declaration would break every Artisan command, Composer's `package:discover` included,
 * the one that installs the fix. Any request or command that reads a type fails instead.
 *
 * @internal
 */
final class ContentTypes
{
    /** `kami_type_<handle>` stays within PostgreSQL's 63 and MySQL's 64 characters (ADR 0006). */
    private const int MAX_HANDLE_LENGTH = 40;

    /** @var array<mixed> The `kami.types` the registry was built from. */
    private array $classes = [];

    /** @var array<mixed> The `kami.types` last found valid. */
    private array $validated = [];

    /** @var array<string, ContentType> */
    private array $types = [];

    public function find(string $classOrHandle): ?ContentType
    {
        return collect($this->all())->first(fn (ContentType $type, string $class): bool => $class === $classOrHandle || $type->handle() === $classOrHandle);
    }

    /**
     * Keyed by class.
     *
     * @return array<string, ContentType>
     */
    public function all(): array
    {
        $types = $this->declared();

        if ($this->classes !== $this->validated) {
            $this->validate($types);
            $this->validated = $this->classes;
        }

        return $types;
    }

    /**
     * Unvalidated, for the panel: it registers one Resource per type while the application boots.
     *
     * @return array<string, ContentType>
     */
    public function declared(): array
    {
        // Rebuilt only when `kami.types` changes, as in a test.
        if (($classes = Config::array('kami.types')) !== $this->classes) {
            $this->types = [];

            foreach ($classes as $class) {
                $type = is_string($class) ? app()->make($class) : null;

                if (! $type instanceof ContentType) {
                    throw new InvalidArgumentException('Every entry of kami.types is a class extending ' . ContentType::class . '.');
                }

                $this->types[$class] = $type;
            }

            $this->classes = $classes;
        }

        return $this->types;
    }

    /** @param array<string, ContentType> $types */
    private function validate(array $types): void
    {
        $handles = [];

        foreach ($types as $class => $type) {
            $this->validateHandle($class, 'the handle', $type->handle());

            if (isset($handles[$type->handle()])) {
                throw new InvalidArgumentException("{$class}: the handle \"{$type->handle()}\" is already the handle of {$handles[$type->handle()]}; override handle() on one of them.");
            }

            $handles[$type->handle()] = $class;
            $fields = [];

            foreach ($type->flatFields() as $field) {
                $this->validateHandle($class, 'the Field handle', $field->handle);

                if (in_array($field->handle, $fields, true)) {
                    throw new InvalidArgumentException("{$class}: the Field handle \"{$field->handle}\" appears twice.");
                }

                if (in_array($field->handle, Field::RESERVED_HANDLES, true)) {
                    throw new InvalidArgumentException("{$class}: the Field handle \"{$field->handle}\" is reserved by Kami's own attributes; rename the Field.");
                }

                // `Entry::getAttribute()` would return the method's relation or nothing, never the Field's value.
                if (method_exists(Entry::class, $field->handle)) {
                    throw new InvalidArgumentException("{$class}: the Field handle \"{$field->handle}\" names a method of " . Entry::class . ', which would hide its value; rename the Field.');
                }

                if ($field->isIndexed() && in_array($field->column(), ['text', 'json'], true)) {
                    throw new InvalidArgumentException("{$class}: the Field \"{$field->handle}\" cannot be filterable or sortable: no engine indexes its {$field->column()} column whole.");
                }

                if ($field instanceof Select && $field->optionKeys() === []) {
                    throw new InvalidArgumentException("{$class}: the Select \"{$field->handle}\" has no options; declare them with ->options([...]).");
                }

                if ($field instanceof Select && ! array_is_list($field->optionKeys())) {
                    throw new InvalidArgumentException("{$class}: the Select \"{$field->handle}\" takes a list of option keys; its labels live in kami-types.php.");
                }

                $fields[] = $field->handle;
            }

            $this->validateLayout($class, $type);
            $this->validateConditions($class, $type);
        }
    }

    private function validateLayout(string $class, ContentType $type): void
    {
        $handles = [];

        foreach ($type->layouts() as $layout) {
            $this->validateHandle($class, 'the layout handle', $layout->handle);

            if (in_array($layout->handle, $handles, true)) {
                throw new InvalidArgumentException("{$class}: the layout handle \"{$layout->handle}\" appears twice.");
            }

            $handles[] = $layout->handle;

            foreach ($layout->children as $child) {
                if ($child instanceof Tab) {
                    throw new InvalidArgumentException("{$class}: the Tab \"{$child->handle}\" cannot sit inside a layout.");
                }

                if ($child instanceof Section && $layout instanceof Section) {
                    throw new InvalidArgumentException("{$class}: the Section \"{$child->handle}\" can only sit inside a Tab.");
                }
            }
        }
    }

    /**
     * A chain would hide a Field by a target itself hidden, whose stale state the form keeps; a Shared value cannot depend on a Locale's (ADR 0007).
     */
    private function validateConditions(string $class, ContentType $type): void
    {
        $fields = collect($type->flatFields())->keyBy('handle');

        foreach ($fields as $field) {
            if ($field->target() === null) {
                continue;
            }

            $target = $fields->get($field->target()) ?? throw new InvalidArgumentException("{$class}: the Field \"{$field->handle}\" is shown according to \"{$field->target()}\", which the type does not declare.");

            if ($target->target() !== null) {
                throw new InvalidArgumentException("{$class}: the Field \"{$field->handle}\" is shown according to \"{$target->handle}\", which is itself conditional.");
            }

            if (! $type->isTranslatable($field) && $type->isTranslatable($target)) {
                throw new InvalidArgumentException("{$class}: the Shared Field \"{$field->handle}\" is shown according to the Translatable \"{$target->handle}\"; make one match the other.");
            }
        }
    }

    private function validateHandle(string $class, string $what, string $handle): void
    {
        if (preg_match('/^[a-z][a-z0-9]*(_[a-z0-9]+)*$/', $handle) !== 1) {
            throw new InvalidArgumentException("{$class}: {$what} \"{$handle}\" is not snake_case: lowercase letters and digits, words joined by single underscores.");
        }

        if (strlen($handle) > self::MAX_HANDLE_LENGTH) {
            throw new InvalidArgumentException("{$class}: {$what} \"{$handle}\" is longer than " . self::MAX_HANDLE_LENGTH . ' characters.');
        }
    }
}
