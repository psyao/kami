<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Fields\Field;

/**
 * The Content Types listed in `config/kami.php`, found by class or handle, validated when first built: at boot (ADR 0007).
 *
 * @internal
 */
final class ContentTypes
{
    /** `kami_type_<handle>` stays within PostgreSQL's 63 and MySQL's 64 characters (ADR 0006). */
    private const int MAX_HANDLE_LENGTH = 40;

    /** @var array<mixed> The `kami.types` the registry was built from. */
    private array $classes = [];

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
        // Rebuilt only when `kami.types` changes, as in a test.
        if (($classes = Config::array('kami.types')) !== $this->classes) {
            $this->types = $this->build($classes);
            $this->classes = $classes;
        }

        return $this->types;
    }

    /**
     * @param  array<mixed>  $classes
     * @return array<string, ContentType>
     */
    private function build(array $classes): array
    {
        $types = [];

        foreach ($classes as $class) {
            $type = is_string($class) ? app()->make($class) : null;

            if (! $type instanceof ContentType) {
                throw new InvalidArgumentException('Every entry of kami.types is a class extending ' . ContentType::class . '.');
            }

            $this->validateHandle($class, 'the handle', $type->handle());

            foreach ($types as $other => $declared) {
                if ($declared->handle() === $type->handle()) {
                    throw new InvalidArgumentException("{$class}: the handle \"{$type->handle()}\" is already the handle of {$other}; override handle() on one of them.");
                }
            }

            $handles = [];

            foreach ($type->fields() as $field) {
                $this->validateHandle($class, 'the Field handle', $field->handle);

                if (in_array($field->handle, $handles, true)) {
                    throw new InvalidArgumentException("{$class}: the Field handle \"{$field->handle}\" appears twice.");
                }

                // A column or a method of `Entry` would hide the Field's value (`Entry::getAttribute()`).
                if (in_array($field->handle, Field::RESERVED_HANDLES, true) || method_exists(Entry::class, $field->handle)) {
                    throw new InvalidArgumentException("{$class}: the Field handle \"{$field->handle}\" is reserved by Kami's own attributes; rename the Field.");
                }

                $handles[] = $field->handle;
            }

            $types[$class] = $type;
        }

        return $types;
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
