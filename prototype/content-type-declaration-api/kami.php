<?php

// PROTOTYPE, throwaway. Just enough of a fake Kami core for the declarations
// under sites/ to load and for run.php to print what Kami would derive from them.
// Nothing here is a design for the real classes' internals.

namespace Psyao\Kami\Content;

abstract class ContentType
{
    /** URL prefix; null = no URL of its own (only pulled into other pages). */
    protected ?string $prefix = null;

    protected bool $hierarchical = false;

    /** Ordered by hand (Position). */
    protected bool $orderable = false;

    /** Optional site-owned Entry subclass, e.g. App\Models\Project, for methods like host(). */
    protected ?string $model = null;

    /** @return list<Field|Layout> */
    abstract public function fields(): array;

    /** snake_case of the class name; override only to keep a handle after a rename. */
    public function handle(): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', (new \ReflectionClass($this))->getShortName()));
    }

    public function kind(): string
    {
        return 'collection';
    }

    /** @internal what run.php prints */
    public function describe(): array
    {
        return [
            'kind' => $this->kind(),
            'prefix' => $this->prefix,
            'hierarchical' => $this->hierarchical,
            'orderable' => $this->orderable,
            'model' => $this->model ?? 'Psyao\Kami\Content\Entry',
        ];
    }
}

/** Exactly one Entry per Site. */
abstract class Singleton extends ContentType
{
    public function kind(): string
    {
        return 'singleton';
    }
}

/** A Singleton with no URL. */
abstract class GlobalSet extends Singleton
{
    public function kind(): string
    {
        return 'global set';
    }
}

/** A Content Type preset whose Entries (Terms) classify other Entries. */
abstract class Taxonomy extends ContentType
{
    public function kind(): string
    {
        return 'taxonomy';
    }

    /** Terms usually have no Field of their own: title and slug are core. */
    public function fields(): array
    {
        return [];
    }
}

abstract class Field
{
    /** Laravel column type; null = no column (lives in kami_references). */
    protected const ?string COLUMN = 'string';

    /** Whether a Field of this kind is Translatable unless told otherwise. */
    protected const bool TRANSLATABLE_BY_DEFAULT = true;

    protected ?bool $translatable = null;
    protected bool $required = false;
    protected bool $indexed = false;
    protected array $rules = [];
    protected mixed $default = null;
    protected ?array $when = null;
    protected ?string $help = null;

    final public function __construct(public readonly string $handle) {}

    public static function make(string $handle): static
    {
        return new static($handle);
    }

    public function shared(): static { $this->translatable = false; return $this; }
    public function translatable(): static { $this->translatable = true; return $this; }
    /** Checked at publication, never on a draft (ADR 0006: every column is nullable). */
    public function required(): static { $this->required = true; return $this; }
    /** Any Laravel validation rule. */
    public function rules(string|array $rules): static { $this->rules = [...$this->rules, ...(array) $rules]; return $this; }
    public function default(mixed $value): static { $this->default = $value; return $this; }
    /** Indexed column, usable in where()/orderBy() on the local API. */
    public function filterable(): static { $this->indexed = true; return $this; }
    public function sortable(): static { $this->indexed = true; return $this; }
    /** Shown, validated and stored only while another Field of the same type has this value. */
    public function visibleWhen(string $field, mixed $equals = true): static { $this->when = [$field, $equals]; return $this; }
    public function visibleWhenFilled(string $field): static { $this->when = [$field, 'filled']; return $this; }
    /** Help text key or text; labels never go here, they come from lang/<locale>/kami-types.php. */
    public function help(string $text): static { $this->help = $text; return $this; }

    public function isTranslatable(): bool
    {
        return $this->translatable ?? static::TRANSLATABLE_BY_DEFAULT;
    }

    /** @internal */
    public function describe(): array
    {
        return array_filter([
            'kind' => (new \ReflectionClass($this))->getShortName(),
            'column' => static::COLUMN,
            'scope' => $this->isTranslatable() ? 'translatable' : 'shared',
            'index' => $this->indexed ?: null,
            'rules' => $this->validationRules() ?: null,
            'default' => $this->default,
            'visibleWhen' => $this->when ? "{$this->when[0]} = " . var_export($this->when[1], true) : null,
            ...$this->extra(),
        ], fn ($v) => $v !== null);
    }

    protected function validationRules(): array
    {
        return [...($this->required ? ['required (on publish)'] : []), ...$this->rules];
    }

    protected function extra(): array { return []; }
}

namespace Psyao\Kami\Content\Fields;

use Psyao\Kami\Content\Block;
use Psyao\Kami\Content\Field;

final class Text extends Field
{
    protected int $max = 255;
    /** Inline Markdown (bold, italic, links) rendered by Kami, not by each site (aguet.dev). */
    protected bool $inlineMarkdown = false;
    public function max(int $max): static { $this->max = $max; return $this; }
    public function inlineMarkdown(): static { $this->inlineMarkdown = true; return $this; }
    protected function validationRules(): array { return [...parent::validationRules(), "max:{$this->max}"]; }
    protected function extra(): array { return ['inlineMarkdown' => $this->inlineMarkdown ?: null]; }
}

final class Textarea extends Field
{
    protected const ?string COLUMN = 'text';
    protected bool $inlineMarkdown = false;
    public function inlineMarkdown(): static { $this->inlineMarkdown = true; return $this; }
    protected function extra(): array { return ['inlineMarkdown' => $this->inlineMarkdown ?: null]; }
}

final class Url extends Field
{
    protected const bool TRANSLATABLE_BY_DEFAULT = false;
    protected function validationRules(): array { return [...parent::validationRules(), 'url:https']; }
}

final class Email extends Field
{
    protected const bool TRANSLATABLE_BY_DEFAULT = false;
    protected function validationRules(): array { return [...parent::validationRules(), 'email']; }
}

final class Boolean extends Field
{
    protected const ?string COLUMN = 'boolean';
    protected const bool TRANSLATABLE_BY_DEFAULT = false;
}

final class Date extends Field
{
    protected const ?string COLUMN = 'date';
    protected const bool TRANSLATABLE_BY_DEFAULT = false;
}

final class Number extends Field
{
    protected const ?string COLUMN = 'integer';
    protected const bool TRANSLATABLE_BY_DEFAULT = false;
}

final class Select extends Field
{
    protected array $options = [];
    /** Option keys are stored; their labels come from translation files. */
    public function options(array $keys): static { $this->options = $keys; return $this; }
    protected const bool TRANSLATABLE_BY_DEFAULT = false;
    protected function validationRules(): array { return [...parent::validationRules(), 'in:' . implode(',', $this->options)]; }
}

/** Format and Block storage are decided in "Editing: rich text format and blocks". */
final class RichText extends Field
{
    protected const ?string COLUMN = 'json';
    protected array $blocks = [];
    protected bool $code = false;
    /** @param list<class-string<Block>> $blocks */
    public function blocks(array $blocks): static { $this->blocks = $blocks; return $this; }
    public function code(): static { $this->code = true; return $this; }
    protected function extra(): array
    {
        return [
            'blocks' => $this->blocks ? implode(', ', array_map(fn ($b) => (new $b)->handle(), $this->blocks)) : null,
            'code' => $this->code ?: null,
        ];
    }
}

/** Opaque json document of rows; never filtered in SQL (ADR 0006). */
final class Repeater extends Field
{
    protected const ?string COLUMN = 'json';
    protected array $fields = [];
    public function fields(array $fields): static { $this->fields = $fields; return $this; }
    protected function extra(): array { return ['rows' => implode(', ', array_map(fn ($f) => $f->handle, $this->fields))]; }
}

/** References to other Entries, stored in kami_references (ADR 0005). Shared by default. */
final class Entries extends Field
{
    protected const ?string COLUMN = null;
    protected const bool TRANSLATABLE_BY_DEFAULT = false;
    protected array $types = [];
    protected bool $multiple = false;
    protected bool $creatable = false;
    /** @param class-string<\Psyao\Kami\Content\ContentType> ...$types */
    public function of(string ...$types): static { $this->types = $types; return $this; }
    public function multiple(): static { $this->multiple = true; return $this; }
    /** Let the editor create a Term from the picker (Taxonomies only). */
    public function creatable(): static { $this->creatable = true; return $this; }
    protected function extra(): array
    {
        return [
            'to' => implode('|', array_map(fn ($t) => (new $t)->handle(), $this->types)),
            'multiple' => $this->multiple ?: null,
            'creatable' => $this->creatable ?: null,
        ];
    }
}

/** References to Media items, stored in kami_references. Shared by default. */
final class Media extends Field
{
    protected const ?string COLUMN = null;
    protected const bool TRANSLATABLE_BY_DEFAULT = false;
    protected bool $multiple = false;
    protected bool $images = false;
    public function multiple(): static { $this->multiple = true; return $this; }
    public function images(): static { $this->images = true; return $this; }
    protected function extra(): array { return ['multiple' => $this->multiple ?: null, 'imagesOnly' => $this->images ?: null]; }
}

namespace Psyao\Kami\Content;

/** Layout only: never stored, never changes a column. Field handles stay unique across the whole type. */
abstract class Layout
{
    final public function __construct(public readonly string $key, public readonly array $fields) {}

    /** $key names the label in lang/<locale>/kami-types.php. */
    public static function make(string $key, array $fields): static
    {
        return new static($key, $fields);
    }
}

final class Tab extends Layout {}

final class Section extends Layout {}

/** A Block offered inside RichText. Its storage and rendering belong to "Editing: rich text format and blocks". */
abstract class Block
{
    /** Stored in every node: never change it once content exists. */
    public function handle(): string
    {
        return strtolower((new \ReflectionClass($this))->getShortName());
    }

    /** @return list<Field> */
    abstract public function fields(): array;

    /** Defaults to blocks.<handle>. */
    public function view(): string
    {
        return 'blocks.' . $this->handle();
    }
}

namespace Psyao\Kami\Content\Blocks;

use Psyao\Kami\Content\Block;
use Psyao\Kami\Content\Fields\Url;

/** Kami's own opt-in Block: a YouTube or Vimeo watch URL. */
final class Video extends Block
{
    public function fields(): array
    {
        return [Url::make('url')->required()];
    }
}
