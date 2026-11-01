<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin;

use Closure;
use Filament\AvatarProviders\UiAvatarsProvider;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field as FormField;
use Filament\Forms\Components\Select as SelectInput;
use Filament\Forms\Components\Textarea as TextareaInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Filament\Resources\ResourceConfiguration;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Assets\Css;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Config;
use Livewire\Component as LivewireComponent;
use LogicException;
use Psyao\Kami\Admin\Internal\EntryResource;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Fields\Boolean;
use Psyao\Kami\Content\Fields\Date;
use Psyao\Kami\Content\Fields\DateTime;
use Psyao\Kami\Content\Fields\Email;
use Psyao\Kami\Content\Fields\Entries;
use Psyao\Kami\Content\Fields\Field;
use Psyao\Kami\Content\Fields\Number;
use Psyao\Kami\Content\Fields\Select;
use Psyao\Kami\Content\Fields\StructuredField;
use Psyao\Kami\Content\Fields\Terms;
use Psyao\Kami\Content\Fields\Text;
use Psyao\Kami\Content\Fields\Textarea;
use Psyao\Kami\Content\Fields\Url;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Sites\Internal\Site;
use Psyao\Kami\Support\Internal\Install\PluginRegistration;

/** Kami's admin, added by the host to its own panel with `->plugin(KamiPlugin::make())`. */
final class KamiPlugin implements Plugin
{
    /** @var array<class-string<Field>, Closure> */
    private array $fieldComponents = [];

    /** @var array<class-string<ContentType>, class-string<EntryForm>> */
    private array $forms = [];

    /** @var array<class-string<ContentType>, class-string<EntryTable>> */
    private array $tables = [];

    public static function make(): self
    {
        return (new self)->fieldComponents([
            Boolean::class => fn (Boolean $field): Toggle => Toggle::make($field->handle),
            Date::class => fn (Date $field): DatePicker => DatePicker::make($field->handle),
            DateTime::class => fn (DateTime $field): DateTimePicker => DateTimePicker::make($field->handle)->timezone(Site::current()->timezone)->suffix(Site::current()->timezone),
            Entries::class => EntryResource::picker(...),
            Email::class => fn (Email $field): TextInput => TextInput::make($field->handle)->email(),
            Number::class => fn (Number $field): TextInput => TextInput::make($field->handle)->numeric()->step($field->column() === 'decimal' ? 0.01 : 1),
            Select::class => fn (Select $field): SelectInput => SelectInput::make($field->handle)->options(array_combine($field->optionKeys(), array_map(fn (string $option): string => EntryResource::type()->optionLabel($field->handle, $option), $field->optionKeys()))),
            Terms::class => EntryResource::picker(...),
            Text::class => fn (Text $field): TextInput => TextInput::make($field->handle),
            Textarea::class => fn (Textarea $field): TextareaInput => TextareaInput::make($field->handle),
            Url::class => fn (Url $field): TextInput => TextInput::make($field->handle)->url(),
        ]);
    }

    /**
     * The admin component of each Field type, by its exact class: a closure given the Field. A Field type with no component stops the panel.
     *
     * @param  array<class-string<Field>, Closure>  $components
     */
    public function fieldComponents(array $components): self
    {
        $this->fieldComponents = [...$this->fieldComponents, ...$components];

        return $this;
    }

    /**
     * A type's own form, in place of the generated one.
     *
     * @param  array<class-string<ContentType>, class-string<EntryForm>>  $forms
     */
    public function forms(array $forms): self
    {
        $this->forms = [...$this->forms, ...$forms];

        return $this;
    }

    /**
     * A type's own table, in place of the generated one.
     *
     * @param  array<class-string<ContentType>, class-string<EntryTable>>  $tables
     */
    public function tables(array $tables): self
    {
        $this->tables = [...$this->tables, ...$tables];

        return $this;
    }

    /** @internal */
    public function entryForm(ContentType $type, HasSchemas & LivewireComponent $livewire): EntryForm
    {
        return new ($this->forms[$type::class] ?? EntryForm::class)($livewire);
    }

    /** @internal */
    public function entryTable(ContentType $type): EntryTable
    {
        return new ($this->tables[$type::class] ?? EntryTable::class);
    }

    /** @internal */
    public function component(Field $field): FormField
    {
        $component = ($this->fieldComponents[$field::class])($field);

        return $component instanceof FormField ? $component : throw new LogicException('The component registered for the Field type ' . $field::class . ' is not a ' . FormField::class . '.');
    }

    public function getId(): string
    {
        return 'kami';
    }

    public function register(Panel $panel): void
    {
        $registration = resolve(PluginRegistration::class);
        $registration->onAPanel = true;
        $registration->panels[spl_object_id($panel)] = fn (): array => [
            'id' => $panel->getId(),
            'guard' => $panel->getAuthGuard(),
            'strict' => $panel->isAuthorizationStrict(),
            // Filament's default sends Users' initials to ui-avatars.com; the doctor warns, since the setting is the host's (ADR 0023).
            'remoteAvatars' => is_a($panel->getDefaultAvatarProvider(), UiAvatarsProvider::class, true),
            // Filament's own rule (its `Authenticate` middleware): a User that is not a FilamentUser gets in locally only.
            'admits' => fn (Authenticatable $user): bool => $user instanceof FilamentUser ? $user->canAccessPanel($panel) : config('app.env') === 'local',
            // A panel does not run the `web` group, so it needs `SecurityHeaders` of its own (ADR 0018).
            'middleware' => array_values(array_filter($panel->getMiddleware(), is_string(...))),
        ];

        // One registration per declared type, each with its own URL, navigation item and Permissions; the class comes from config, the door for overridable Resources in V1 (ADR 0029).
        $resource = Config::string('kami.resources.entry', EntryResource::class);

        if (! is_a($resource, EntryResource::class, true)) {
            throw new LogicException('kami.resources.entry names ' . EntryResource::class . ' or a subclass.');
        }

        // Added twice to one panel, the plugin registers its types once.
        foreach (resolve(ContentTypes::class)->declared() as $type) {
            if (! $panel->getResourceConfiguration($resource, $type->handle()) instanceof ResourceConfiguration) {
                $panel->resources([$resource::configure($type)]);
            }
        }

        // Precompiled, so a site needs neither Node nor a custom theme for Kami's admin (ADR 0023).
        $panel->assets([Css::make('kami', __DIR__ . '/../../resources/dist/kami.css')], 'psyao/kami');
    }

    public function boot(Panel $panel): void
    {
        // On the panel's own requests only: `register()` runs for Artisan too, which must keep working to fix a declaration (see `ContentTypes`).
        foreach (resolve(ContentTypes::class)->declared() as $type) {
            foreach ($type->flatFields() as $field) {
                $this->requireComponent($field, $type);
            }
        }
    }

    private function requireComponent(Field $field, ContentType $type): void
    {
        if ($field instanceof StructuredField) {
            foreach ($field->fields() as $sub) {
                $this->requireComponent($sub, $type);
            }
        } elseif (! isset($this->fieldComponents[$field::class])) {
            throw new LogicException('No admin component for the Field type ' . $field::class . ' of ' . $type::class . '; register one with KamiPlugin::make()->fieldComponents([...]).');
        }
    }
}
