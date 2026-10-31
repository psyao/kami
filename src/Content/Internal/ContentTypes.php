<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Fields\Field;
use Psyao\Kami\Content\Fields\Group;
use Psyao\Kami\Content\Fields\RelationField;
use Psyao\Kami\Content\Fields\Select;
use Psyao\Kami\Content\Fields\StructuredField;
use Psyao\Kami\Content\Fields\Terms;
use Psyao\Kami\Content\GlobalSet;
use Psyao\Kami\Content\Section;
use Psyao\Kami\Content\Singleton;
use Psyao\Kami\Content\SiteSettings;
use Psyao\Kami\Content\Tab;
use Psyao\Kami\Content\Taxonomy;
use Psyao\Kami\Content\Term;

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

    /** @var array<mixed>|null The `kami.types` the registry was built from. */
    private ?array $classes = null;

    /** @var array<mixed>|null The `kami.types` last found valid. */
    private ?array $validated = null;

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

            // `SiteSettings` is always there, listed or not: its table ships with the package.
            foreach ([...$classes, SiteSettings::class] as $class) {
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
            $this->validateModel($class, $type);
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
                if (method_exists($type->model(), $field->handle)) {
                    throw new InvalidArgumentException("{$class}: the Field handle \"{$field->handle}\" names a method of " . $type->model() . ', which would hide its value; rename the Field.');
                }

                if ($field instanceof RelationField) {
                    $this->validateRelation($class, $field, $types);
                }

                if ($field->isIndexed() && in_array($field->column(), ['text', 'json'], true)) {
                    throw new InvalidArgumentException("{$class}: the Field \"{$field->handle}\" cannot be filterable or sortable: no engine indexes its {$field->column()} column whole.");
                }

                $this->validateSelect($class, $field);

                if ($field instanceof StructuredField) {
                    $this->validateSubFields($class, $field);
                }

                $fields[] = $field->handle;
            }

            if ($type instanceof Singleton && $type->isHierarchical()) {
                throw new InvalidArgumentException("{$class}: a Singleton has one Entry, so it cannot be hierarchical; remove \$hierarchical.");
            }

            if ($type instanceof GlobalSet) {
                $this->validateGlobalSet($class, $type);
            }

            $this->validateLayout($class, $type);
            $this->validateConditions($class, $type);
        }
    }

    /** A Taxonomy's Terms are promised as `Term`s to whatever links them. */
    private function validateModel(string $class, ContentType $type): void
    {
        $base = $type instanceof Taxonomy ? Term::class : Entry::class;

        // @phpstan-ignore function.alreadyNarrowedType (what a declaration promises is what this checks)
        if (! is_a($type->model(), $base, true)) {
            throw new InvalidArgumentException("{$class}: its \$model {$type->model()} must extend {$base}.");
        }
    }

    /** @param array<string, ContentType> $types */
    private function validateRelation(string $class, RelationField $field, array $types): void
    {
        if ($field->targets() === []) {
            throw new InvalidArgumentException("{$class}: the Field \"{$field->handle}\" links to nothing; name its Content Types with ->of(...).");
        }

        foreach ($field->targets() as $target) {
            if (! isset($types[$target])) {
                throw new InvalidArgumentException("{$class}: the Field \"{$field->handle}\" links to {$target}, which is not listed in kami.types.");
            }
        }

        foreach ($field instanceof Terms ? $field->targets() : [] as $target) {
            if (! $types[$target] instanceof Taxonomy) {
                throw new InvalidArgumentException("{$class}: the Terms \"{$field->handle}\" links to {$target}, which is no Taxonomy; use Entries.");
            }
        }

        if ($field->isCreatable()) {
            $this->validateQuickCreate($class, $field, $types);
        }

        if ($field->isIndexed()) {
            throw new InvalidArgumentException("{$class}: the relation \"{$field->handle}\" cannot be filterable or sortable: it has no column.");
        }

        if ($field->defaultValue() !== null) {
            throw new InvalidArgumentException("{$class}: the relation \"{$field->handle}\" cannot take a default: no Entry id is known when the type is declared.");
        }
    }

    /**
     * The picker's form asks for a title and nothing else, for one type.
     *
     * @param  array<string, ContentType>  $types
     */
    private function validateQuickCreate(string $class, RelationField $field, array $types): void
    {
        if (count($field->targets()) !== 1) {
            throw new InvalidArgumentException("{$class}: the relation \"{$field->handle}\" links to several types, and its picker creates one; add ->creatable(false).");
        }

        $target = $types[$field->targets()[0]];

        if ($target instanceof Singleton) {
            throw new InvalidArgumentException("{$class}: the relation \"{$field->handle}\" links to " . $target::class . ', which holds one Entry, so its picker cannot create another; add ->creatable(false) or remove ->creatable().');
        }

        foreach ($target->flatFields() as $targetField) {
            if ($this->lacksValue($targetField, $targetField->defaultValue())) {
                throw new InvalidArgumentException("{$class}: the relation \"{$field->handle}\" links to " . $target::class . ", whose Field \"{$targetField->handle}\" is required with no default, and its picker asks for a title alone; give it a ->default() or add ->creatable(false).");
            }
        }
    }

    private function validateSelect(string $class, Field $field): void
    {
        if ($field instanceof Select && $field->optionKeys() === []) {
            throw new InvalidArgumentException("{$class}: the Select \"{$field->handle}\" has no options; declare them with ->options([...]).");
        }

        if ($field instanceof Select && ! array_is_list($field->optionKeys())) {
            throw new InvalidArgumentException("{$class}: the Select \"{$field->handle}\" takes a list of option keys; its labels live in kami-types.php.");
        }
    }

    /** A condition, an index or a default has no meaning inside a document: nothing would honour it. */
    private function validateSubFields(string $class, StructuredField $parent): void
    {
        if ($parent->subFields() === []) {
            throw new InvalidArgumentException("{$class}: the Field \"{$parent->handle}\" has no sub-Field; declare them as the second argument of make().");
        }

        $handles = [];

        foreach ($parent->subFields() as $field) {
            if (! $field instanceof Field) {
                throw new InvalidArgumentException("{$class}: the Field \"{$parent->handle}\" cannot hold the layout \"{$field->handle}\": arrange it around the Field instead.");
            }

            if ($field instanceof RelationField) {
                throw new InvalidArgumentException("{$class}: the Field \"{$parent->handle}\" cannot hold the relation \"{$field->handle}\": its rows live in kami_references, which cannot see inside a document.");
            }

            $this->validateHandle($class, 'the sub-Field handle', $field->handle);

            if (in_array($field->handle, $handles, true)) {
                throw new InvalidArgumentException("{$class}: the sub-Field handle \"{$field->handle}\" appears twice in \"{$parent->handle}\".");
            }

            if ($field->conditionTarget() !== null) {
                throw new InvalidArgumentException("{$class}: the sub-Field \"{$parent->handle}.{$field->handle}\" cannot take a condition.");
            }

            if ($field->isIndexed()) {
                throw new InvalidArgumentException("{$class}: the sub-Field \"{$parent->handle}.{$field->handle}\" cannot be filterable or sortable: its value is inside a json column.");
            }

            if ($field->defaultValue() !== null) {
                throw new InvalidArgumentException("{$class}: the sub-Field \"{$parent->handle}.{$field->handle}\" cannot take a default; give the default to \"{$parent->handle}\".");
            }

            $this->validateSelect($class, $field);

            if ($field instanceof StructuredField) {
                $this->validateSubFields($class, $field);
            }

            $handles[] = $field->handle;
        }
    }

    /** Always published, by `kami:install` before anyone can fill it: nothing may be missing for that. */
    private function validateGlobalSet(string $class, GlobalSet $type): void
    {
        if ($type->prefix() !== $type->handle()) {
            throw new InvalidArgumentException("{$class}: a Global Set has no URL; remove its \$prefix.");
        }

        foreach ($type->flatFields() as $field) {
            if ($field instanceof RelationField && $field->isRequired()) {
                throw new InvalidArgumentException("{$class}: the relation \"{$field->handle}\" is required; a Global Set is always published and a relation takes no default, so kami:install could not create it. Remove ->required().");
            }

            if ($this->lacksValue($field, $field->defaultValue())) {
                throw new InvalidArgumentException("{$class}: the Field \"{$field->handle}\" is required with no default; a Global Set is always published, so kami:install could not create it. Give it a ->default().");
            }
        }
    }

    /** Saved with its default alone, it would be refused: a Group's required sub-Field needs a value as soon as the Group is saved; a Repeater may have no row. */
    private function lacksValue(Field $field, mixed $default): bool
    {
        return ($field->isRequired() && $default === null)
            || ($field instanceof Group && collect($field->fields())->contains(fn (Field $sub, string $handle): bool => $this->lacksValue($sub, is_array($default) ? $default[$handle] ?? null : null)));
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
                    throw new InvalidArgumentException("{$class}: the Section \"{$child->handle}\" sits at the top level or inside a Tab.");
                }
            }
        }
    }

    /**
     * A chain would hide a Field by a target itself hidden, whose stale state the form keeps; a Translatable target would answer differently per Locale, and through Fallback (ADR 0007).
     */
    private function validateConditions(string $class, ContentType $type): void
    {
        $fields = collect($type->flatFields())->keyBy('handle');

        foreach ($fields as $field) {
            if ($field->conditionTarget() === null) {
                continue;
            }

            $target = $fields->get($field->conditionTarget()) ?? throw new InvalidArgumentException("{$class}: the Field \"{$field->handle}\" is shown according to \"{$field->conditionTarget()}\", which the type does not declare.");

            if ($target->conditionTarget() !== null) {
                throw new InvalidArgumentException("{$class}: the Field \"{$field->handle}\" is shown according to \"{$target->handle}\", which is itself conditional.");
            }

            if ($target instanceof StructuredField) {
                throw new InvalidArgumentException("{$class}: the Field \"{$field->handle}\" is shown according to the Repeater or Group \"{$target->handle}\", whose value a condition cannot read.");
            }

            if ($target instanceof RelationField) {
                throw new InvalidArgumentException("{$class}: the Field \"{$field->handle}\" is shown according to the relation \"{$target->handle}\", whose value a condition cannot read.");
            }

            if ($type->isTranslatable($target)) {
                throw new InvalidArgumentException("{$class}: the Field \"{$field->handle}\" is shown according to the Translatable \"{$target->handle}\"; a target holds for every Locale: make it ->shared().");
            }

            $values = $field->conditionValues();

            if ($values === null && $target->column() === 'boolean') {
                throw new InvalidArgumentException("{$class}: the Field \"{$field->handle}\" is shown when the Boolean \"{$target->handle}\" is filled, which it always is; use ->visibleWhen().");
            }

            if ($target instanceof Select && array_filter($values ?? [], fn (mixed $value): bool => ! in_array($value, $target->optionKeys(), true)) !== []) {
                throw new InvalidArgumentException("{$class}: the Field \"{$field->handle}\" is shown for a value that is not an option of the Select \"{$target->handle}\".");
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
