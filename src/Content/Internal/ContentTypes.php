<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Fields\Field;

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

            foreach ($type->fields() as $field) {
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

                $fields[] = $field->handle;
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
