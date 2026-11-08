<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin;

use Closure;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Auth\Pages\Login as FilamentLogin;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as FilamentRequestPasswordReset;
use Filament\AvatarProviders\UiAvatarsProvider;
use Filament\Contracts\Plugin;
use Filament\Enums\GlobalSearchPosition;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field as FormField;
use Filament\Forms\Components\Select as SelectInput;
use Filament\Forms\Components\Textarea as TextareaInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\GlobalSearch\Providers\Contracts\GlobalSearchProvider;
use Filament\GlobalSearch\Providers\DefaultGlobalSearchProvider;
use Filament\Livewire\GlobalSearch;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Filament\Resources\ResourceConfiguration;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Livewire\Component as LivewireComponent;
use Livewire\Livewire;
use LogicException;
use Psyao\Kami\Admin\Internal\AppAuthentication;
use Psyao\Kami\Admin\Internal\AuditEventResource;
use Psyao\Kami\Admin\Internal\EntryResource;
use Psyao\Kami\Admin\Internal\KamiGlobalSearchProvider;
use Psyao\Kami\Admin\Internal\Login;
use Psyao\Kami\Admin\Internal\MediaResource;
use Psyao\Kami\Admin\Internal\NoIndex;
use Psyao\Kami\Admin\Internal\RenderPreview;
use Psyao\Kami\Admin\Internal\RequestPasswordReset;
use Psyao\Kami\Admin\Internal\RequireMfa;
use Psyao\Kami\Admin\Internal\RichTextInput;
use Psyao\Kami\Admin\Internal\Widgets\NeedsAttention;
use Psyao\Kami\Admin\Internal\Widgets\RecentlyEdited;
use Psyao\Kami\Admin\Internal\Widgets\Scheduled;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Fields\Boolean;
use Psyao\Kami\Content\Fields\Date;
use Psyao\Kami\Content\Fields\DateTime;
use Psyao\Kami\Content\Fields\Email;
use Psyao\Kami\Content\Fields\Entries;
use Psyao\Kami\Content\Fields\Field;
use Psyao\Kami\Content\Fields\Media as MediaField;
use Psyao\Kami\Content\Fields\Number;
use Psyao\Kami\Content\Fields\RichText;
use Psyao\Kami\Content\Fields\Select;
use Psyao\Kami\Content\Fields\StructuredField;
use Psyao\Kami\Content\Fields\Terms;
use Psyao\Kami\Content\Fields\Text;
use Psyao\Kami\Content\Fields\Textarea;
use Psyao\Kami\Content\Fields\Url;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Sites\Internal\Site;
use Psyao\Kami\Support\Internal\FrontSecurityHeaders;
use Psyao\Kami\Support\Internal\Install\PluginRegistration;
use Psyao\Kami\Support\SecurityHeaders;

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
            MediaField::class => MediaResource::picker(...),
            Email::class => fn (Email $field): TextInput => TextInput::make($field->handle)->email(),
            Number::class => fn (Number $field): TextInput => TextInput::make($field->handle)->numeric()->step($field->column() === 'decimal' ? 0.01 : 1),
            Select::class => fn (Select $field): SelectInput => SelectInput::make($field->handle)->options(array_combine($field->optionKeys(), array_map(fn (string $option): string => EntryResource::type()->optionLabel($field->handle, $option), $field->optionKeys()))),
            RichText::class => RichTextInput::make(...),
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
        $registration->userName = EntryResource::userName(...);
        EntryResource::relateUsers();
        $registration->guards[spl_object_id($panel)] = $panel->getAuthGuard(...);
        $registration->panels[spl_object_id($panel)] = fn (): array => [
            'id' => $panel->getId(),
            'guard' => $panel->getAuthGuard(),
            'strict' => $panel->isAuthorizationStrict(),
            // Where `robots.txt` disallows it (ADR 0027).
            'path' => $panel->getPath(),
            'domains' => array_values($panel->getDomains()),
            // Filament's default sends Users' initials to ui-avatars.com; the doctor warns, since the setting is the host's (ADR 0023).
            'remoteAvatars' => is_a($panel->getDefaultAvatarProvider(), UiAvatarsProvider::class, true),
            // Filament's own rule (its `Authenticate` middleware): a User that is not a FilamentUser gets in locally only.
            'admits' => fn (Authenticatable $user): bool => $user instanceof FilamentUser ? $user->canAccessPanel($panel) : config('app.env') === 'local',
            // A panel does not run the `web` group, so it needs `SecurityHeaders` of its own (ADR 0018).
            'middleware' => array_values(array_filter($panel->getMiddleware(), is_string(...))),
            'replacedLoginPage' => $this->replacedPage($panel->hasLogin(), $panel->getLoginRouteAction(), [FilamentLogin::class, Login::class]),
            'replacedResetPage' => $this->replacedPage($panel->hasPasswordReset(), $panel->getRequestPasswordResetRouteAction(), [FilamentRequestPasswordReset::class, RequestPasswordReset::class]),
            'setPasswordLink' => $panel->hasPasswordReset() ? fn (CanResetPassword $user): array => SetPasswordLink::make($user, $panel) : null,
            // A provider or requirement set after the plugin replaces Kami's, and with it the rule for Users who may manage Users (ADR 0014).
            'requiresKamiMfa' => $panel->isMultiFactorAuthenticationRequired() && array_any($panel->getMultiFactorAuthenticationProviders(), fn (object $provider): bool => $provider instanceof AppAuthentication),
            'storesMfa' => fn (string $model): bool => is_a($model, HasAppAuthentication::class, true) && is_a($model, HasAppAuthenticationRecovery::class, true),
            // A secret the app key no longer decrypts, after `APP_KEY` rotated without `APP_PREVIOUS_KEYS`, signs nobody in: no MFA, until `kami:user:reset-mfa`.
            'hasMfa' => fn (Authenticatable $user): bool => $user instanceof HasAppAuthentication && rescue(fn (): bool => filled($user->getAppAuthenticationSecret()), false, report: false),
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

        $media = Config::string('kami.resources.media', MediaResource::class);

        if (! is_a($media, MediaResource::class, true)) {
            throw new LogicException('kami.resources.media names ' . MediaResource::class . '.');
        }

        $panel->resources([$media, AuditEventResource::class]);

        // The sign-in page included, whatever the host's own middleware (ADR 0027).
        $panel->middleware([NoIndex::class]);

        // A front page, so the front's headers in place of the panel's (ADR 0018); but for the admin's opener policy,
        // as the front's `same-origin` would move the tab out of the admin's reach and the next Preview would open another.
        // Filament adds its MFA middleware to its own pages only: a manager without MFA is sent to set it up here too.
        $panel->authenticatedRoutes(fn () => Route::get('kami/preview/{token}', RenderPreview::class)
            ->name('kami.preview')
            // Named with its profile too: Laravel before 13.39 only drops a middleware excluded by its exact name, parameters included.
            ->withoutMiddleware([SecurityHeaders::class, SecurityHeaders::class . ':admin'])
            ->middleware([FrontSecurityHeaders::class . ':Cross-Origin-Opener-Policy=unsafe-none', RequireMfa::class]));

        // An authenticator app with recovery codes, set up before any other admin page by a User holding `kami.users.manage`,
        // from the profile page by any other; Filament's profile page unless the host set its own (ADR 0014).
        $panel->multiFactorAuthentication(AppAuthentication::make()->recoverable(), isRequired: true)
            ->multiFactorAuthenticationRequiredMiddlewareName(RequireMfa::class);

        if (! $panel->hasProfile()) {
            $panel->profile();
        }

        // Kami's sign-in and reset request pages in place of Filament's, unless the host set its own: this runs mid-chain, before a later `->login()`
        // or `->passwordReset()`, and `boot()` after the routes, so the swap waits for the panel's route callbacks, which Filament runs just before reading the pages' classes.
        $panel->livewireComponents([Login::class, RequestPasswordReset::class]);
        $panel->routes(function (Panel $panel): void {
            if ($panel->getLoginRouteAction() === FilamentLogin::class) {
                $panel->login(Login::class);
            }

            if ($panel->getRequestPasswordResetRouteAction() === FilamentRequestPasswordReset::class) {
                $panel->passwordReset(RequestPasswordReset::class, $panel->getResetPasswordRouteAction());
            }
        });

        // Filament shows its search field for a searchable Resource of `getResources()` only, which leaves out those registered per configuration.
        // ponytail: in Filament's own hooks beside the field's place, no `x-show` on a collapsed sidebar; V1, or sooner once Filament counts configurations in `isGlobalSearchEnabled()`.
        $panel->renderHook(PanelsRenderHook::GLOBAL_SEARCH_AFTER, fn (): string => $this->searchField(GlobalSearchPosition::Topbar));
        $panel->renderHook(PanelsRenderHook::SIDEBAR_NAV_START, fn (): string => $this->searchField(GlobalSearchPosition::Sidebar));

        // Added to the host's own widgets, each shown by its `canView()`: Kami takes nothing from the host's dashboard.
        // ponytail: no opt-out but a dashboard listing its own widgets; V1 adds one (spec #241, Out of scope).
        $panel->widgets([RecentlyEdited::class, Scheduled::class, NeedsAttention::class]);

        // Precompiled, so a site needs neither Node nor a custom theme for Kami's admin (ADR 0023).
        $panel->assets([Css::make('kami', __DIR__ . '/../../resources/dist/kami.css'), Js::make('kami', __DIR__ . '/../../resources/dist/kami.js')], 'psyao/kami');
    }

    public function boot(Panel $panel): void
    {
        // In boot, after the host's whole `panel()` chain: a provider of its own, or `globalSearch(false)`, wins (ADR 0023).
        if (($provider = $panel->getGlobalSearchProvider()) instanceof GlobalSearchProvider && $provider::class === DefaultGlobalSearchProvider::class) {
            // `globalSearch()` also sets the position: the host's is kept.
            $panel->globalSearch(KamiGlobalSearchProvider::class, $panel->getGlobalSearchPosition());
        }

        // On the panel's own requests only: `register()` runs for Artisan too, which must keep working to fix a declaration (see `ContentTypes`).
        foreach (resolve(ContentTypes::class)->declared() as $type) {
            foreach ($type->flatFields() as $field) {
                $this->requireComponent($field, $type);
            }
        }
    }

    /**
     * The host's own page, `null` while Kami's serves or the panel offers none.
     *
     * @param  string|Closure|array<mixed>|null  $page  The panel's route action for the page.
     * @param  list<class-string>  $servedByKami  Filament's page and Kami's, which the route callback swaps it for.
     */
    private function replacedPage(bool $offered, string | Closure | array | null $page, array $servedByKami): ?string
    {
        // Filament's own page counts as Kami's: the route callback swaps it, and a cached route table keeps the swap.
        if (! $offered || in_array($page, $servedByKami, true)) {
            return null;
        }

        return is_string($page) ? $page : 'its own action';
    }

    /** Filament's search field where the panel places it, when Kami's provider serves it and Filament shows none. */
    private function searchField(GlobalSearchPosition $position): string
    {
        $provider = Filament::getGlobalSearchProvider();

        return $provider instanceof KamiGlobalSearchProvider && ! Filament::isGlobalSearchEnabled() && Filament::getGlobalSearchPosition() === $position && EntryResource::accessibleTypes() !== []
            ? Livewire::mount(GlobalSearch::class)
            : '';
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
