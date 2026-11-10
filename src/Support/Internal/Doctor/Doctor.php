<?php

declare(strict_types=1);

namespace Psyao\Kami\Support\Internal\Doctor;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\SQLiteDatabaseDoesNotExistException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Fields\Field;
use Psyao\Kami\Content\Fields\RichText;
use Psyao\Kami\Content\Fields\Select;
use Psyao\Kami\Content\Fields\StructuredField;
use Psyao\Kami\Content\Internal\ContentModelDrift;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Content\Layout;
use Psyao\Kami\Content\Singleton;
use Psyao\Kami\Content\Tab;
use Psyao\Kami\Media\Internal\PublicMedia;
use Psyao\Kami\Media\Media;
use Psyao\Kami\Routing\Internal\PageCache;
use Psyao\Kami\Routing\Internal\Path;
use Psyao\Kami\Routing\Internal\SyncPaths;
use Psyao\Kami\Scheduling\Internal\Tick;
use Psyao\Kami\Seo\Internal\Sitemap;
use Psyao\Kami\Sites\Internal\Site;
use Psyao\Kami\Support\Internal\Install\PluginRegistration;
use Psyao\Kami\Support\Internal\Install\RobotsTxt;
use Psyao\Kami\Support\Internal\SecurityPolicy;
use Psyao\Kami\Support\Internal\WebEnvironment;
use Psyao\Kami\Support\SecurityHeaders;
use Psyao\Kami\Users\Internal\Permissions;
use Psyao\Kami\Users\KamiUser;
use ReflectionMethod;
use Throwable;

/**
 * The closed list of checks behind `kami:doctor`; later milestones add theirs here.
 *
 * @internal
 *
 * @phpstan-type PanelReport array{id: string, guard: string, strict: bool, path: string, domains: list<string>, remoteAvatars: bool, admits: Closure(Authenticatable): bool, middleware: list<string>, replacedLoginPage: ?string, replacedResetPage: ?string, setPasswordLink: (Closure(CanResetPassword): array{url: string, minutes: int})|null, requiresKamiMfa: bool, storesMfa: Closure(string): bool, hasMfa: Closure(Authenticatable): bool}
 */
final readonly class Doctor
{
    /** The lowest PHP version Kami runs on, as `composer.json` requires it. */
    public const string PHP_FLOOR = '8.5.0';

    private const string SCHEDULER_GUIDE = 'scheduler.md#checking-the-scheduler';

    /** What Kami and its dependencies require at run time, as `composer check-platform-reqs --no-dev` lists it. */
    public const array EXTENSIONS = [
        'ctype', 'dom', 'fileinfo', 'filter', 'hash', 'iconv', 'intl', 'json', 'libxml',
        'mbstring', 'openssl', 'pcre', 'session', 'tokenizer', 'xmlreader', 'xmlwriter', 'zip',
    ];

    /** @var Closure(string): bool */
    private Closure $extensionLoaded;

    /**
     * Both default to the running PHP; a test passes others.
     *
     * @param  (Closure(string): bool)|null  $extensionLoaded
     */
    public function __construct(
        private string $phpVersion = PHP_VERSION,
        ?Closure $extensionLoaded = null,
    ) {
        $this->extensionLoaded = $extensionLoaded ?? extension_loaded(...);
    }

    /** @return list<Problem> */
    public function problems(): array
    {
        $panels = $this->panels();
        // A database Kami cannot reach is already an error, and the table is missing until the first `kami:install`.
        $assignments = rescue(fn (): bool => Schema::hasTable('kami_role_user'), false, report: false);
        $paths = rescue(fn (): bool => Schema::hasTable('kami_paths'), false, report: false);
        $media = rescue(fn (): bool => Schema::hasTable('kami_media'), false, report: false);
        $mfaTable = rescue(fn (): bool => Schema::hasTable('kami_user_mfa'), false, report: false);

        return [
            ...$this->installBlocking($panels),
            ...$this->webPhp(),
            ...array_filter([$this->hotFile()]),
            ...$this->remoteAvatars($panels),
            ...$this->strictAuthorization($panels),
            ...$this->securityHeaders($panels),
            ...$this->insecureTransport(),
            ...$this->replacedPages($panels, 'replacedLoginPage', 'sign-in', 'login', 'throttling sign-in per email', 'limit attempts per email as well as per IP address', 'sign-in'),
            ...$this->replacedPages($panels, 'replacedResetPage', 'password reset', 'passwordReset', 'keeping it from revealing who has an account', 'answer every address the same way, after the response', 'password-reset'),
            ...$this->replacedMfa($panels),
            ...$this->weakeningOptions(),
            ...$this->missingViews(),
            ...$this->undescribedTypes(),
            ...$this->missingLabels(),
            ...array_filter([$this->lockStore(), $this->publicMedia(), $this->pageCacheStorage()]),
            ...$this->searchEngines(),
            ...($paths ? [...$this->contentModel(), ...$this->undeclaredContent(), ...array_filter([$this->hiddenPaths()])] : []),
            ...($assignments ? [...$this->undeclaredRoles(), ...$this->usersWithoutRole($panels)] : []),
            ...($assignments && $mfaTable ? $this->managersWithoutMfa($panels) : []),
            ...($media ? array_filter([$this->missingAlt()]) : []),
            ...$this->scheduler(),
            ...$this->queue(),
        ];
    }

    /**
     * The errors that stop `kami:install` before it migrates.
     *
     * @return list<Problem>
     */
    public function blocking(): array
    {
        return array_values(array_filter($this->installBlocking($this->panels()), fn (Problem $problem): bool => $problem->severity === Severity::Error));
    }

    /**
     * The checks whose errors stop `kami:install`: none may need a migrated table, as they run before it migrates.
     *
     * @param  list<PanelReport>  $panels
     * @return list<Problem>
     */
    private function installBlocking(array $panels): array
    {
        return [
            ...$this->environment(),
            ...$this->userModels($panels),
            ...$this->guardModels($panels),
            ...$this->mfaModels($panels),
            ...array_filter([$this->exposedOriginals()]),
        ];
    }

    /** @return list<Problem> */
    private function environment(): array
    {
        return array_values(array_filter([
            $this->phpVersion(),
            ...$this->extensions(),
            $this->imageExtension(),
            ...$this->writableDirectories(),
            $this->database(),
        ]));
    }

    private function phpVersion(): ?Problem
    {
        if (version_compare($this->phpVersion, self::PHP_FLOOR, '>=')) {
            return null;
        }

        return new Problem(
            Severity::Error,
            "PHP {$this->phpVersion} is below Kami's floor, " . self::PHP_FLOOR . '.',
            'run PHP ' . self::PHP_FLOOR . ' or later, on the command line and on the web.',
            'installation.md#requirements',
        );
    }

    /** @return list<Problem> */
    private function extensions(): array
    {
        $missing = array_filter(self::EXTENSIONS, fn (string $extension): bool => ! ($this->extensionLoaded)($extension));

        return array_values(array_map(fn (string $extension): Problem => new Problem(
            Severity::Error,
            "The PHP extension {$extension} is not loaded.",
            "enable {$extension} for the PHP version the site runs, on the command line and on the web.",
            'installation.md#requirements',
        ), $missing));
    }

    /** Kami still runs without one, but cannot resize images (brief: GD or Imagick, with a clean fallback). */
    private function imageExtension(): ?Problem
    {
        $loaded = $this->extensionLoaded;

        if ($loaded('gd') || $loaded('imagick')) {
            return null;
        }

        return new Problem(
            Severity::Warning,
            'Neither gd nor imagick is loaded: Kami will need one to resize images.',
            'enable gd or imagick for the PHP version the site runs.',
            'installation.md#requirements',
        );
    }

    /** @return list<Problem> */
    private function writableDirectories(): array
    {
        $directories = ['storage' => storage_path(), 'bootstrap/cache' => app()->bootstrapPath('cache')];
        $unwritable = array_filter($directories, fn (string $path): bool => ! is_writable($path));

        // Mapping two arrays at once already returns a list.
        return array_map(fn (string $name, string $path): Problem => new Problem(
            Severity::Error,
            "{$name} ({$path}) is not writable.",
            "create {$path} if it is missing and give the user PHP runs as write access to it.",
            'installation.md#writable-directories',
        ), array_keys($unwritable), $unwritable);
    }

    private function database(): ?Problem
    {
        try {
            DB::connection()->getPdo();

            return null;
        } catch (SQLiteDatabaseDoesNotExistException $exception) {
            return $this->missingSqliteFile($exception->path);
        } catch (Throwable $exception) {
            $name = DB::getDefaultConnection();
            $driver = config("database.connections.{$name}.driver");
            $extension = match ($driver) {
                'mysql', 'mariadb' => 'the PHP extension pdo_mysql',
                'pgsql' => 'the PHP extension pdo_pgsql',
                'sqlite' => 'the PHP extension pdo_sqlite',
                default => 'its PDO driver',
            };

            // MySQL and MariaDB, then PostgreSQL: the credentials reached the server and were refused.
            $refused = str_contains($exception->getMessage(), 'Access denied for user')
                || str_contains($exception->getMessage(), 'password authentication failed');

            return new Problem(
                Severity::Error,
                "Kami cannot connect to the database {$name}: {$exception->getMessage()}",
                $refused
                    ? "check DB_USERNAME and DB_PASSWORD in .env, with the password in single quotes (DB_PASSWORD='...'): unquoted, a # cuts it short. Once php artisan optimize has run, .env edits count only after it runs again."
                    : "check the DB_* settings in .env and that {$extension} is loaded.",
                'installation.md#database',
            );
        }
    }

    /** `migrate --force` creates the file, as on a first deploy from git: only its directory must be ready. */
    private function missingSqliteFile(string $path): ?Problem
    {
        $directory = dirname($path);

        if (is_dir($directory) && is_writable($directory)) {
            return null;
        }

        $name = DB::getDefaultConnection();

        return new Problem(
            Severity::Error,
            "Kami cannot create the SQLite database {$name}: {$directory} is missing or not writable.",
            "create {$directory} if it is missing and give the user PHP runs as write access to it.",
            'installation.md#database',
        );
    }

    /** @return list<PanelReport> */
    private function panels(): array
    {
        return array_values(array_map(fn (Closure $panel): array => $panel(), resolve(PluginRegistration::class)->panels));
    }

    /** The model of the provider behind an auth guard, if it names one. */
    private function userModel(string $guard): ?string
    {
        $provider = config("auth.guards.{$guard}.provider");
        $model = is_string($provider) ? config("auth.providers.{$provider}.model") : null;

        return is_string($model) ? $model : null;
    }

    /**
     * @param  list<PanelReport>  $panels
     * @return list<Problem>
     */
    private function userModels(array $panels): array
    {
        $lacking = array_filter($panels, fn (array $panel): bool => ! is_a((string) $this->userModel($panel['guard']), KamiUser::class, true));

        return array_values(array_map(fn (array $panel): Problem => new Problem(
            Severity::Error,
            "The panel {$panel['id']} signs Users in with " . ($this->userModel($panel['guard']) ?? 'no Eloquent model') . ', which does not implement ' . KamiUser::class . '.',
            'add implements \\Psyao\\Kami\\Users\\KamiUser to the User model, and use \\Psyao\\Kami\\Users\\HasKamiRoles; in its body.',
            'users-and-roles.md#the-user-model',
        ), $lacking));
    }

    /**
     * kami_role_user, authorship and kami:install's first administrator all point at auth.providers.users.model; a host Role source lifts this (ADR 0014, V1).
     *
     * @param  list<PanelReport>  $panels
     * @return list<Problem>
     */
    private function guardModels(array $panels): array
    {
        $users = config('auth.providers.users.model');
        $users = is_string($users) ? ltrim($users, '\\') : null;
        $problems = [];

        foreach ($panels as $panel) {
            // A guard with no Eloquent model is already an error of userModels(); class names are case-insensitive.
            if (($model = $this->userModel($panel['guard'])) === null || strcasecmp($model = ltrim($model, '\\'), (string) $users) === 0) {
                continue;
            }

            $problems[] = new Problem(
                Severity::Error,
                "The panel {$panel['id']} signs Users in with {$model}, while Kami gives Roles to " . ($users ?? 'no model') . ' (auth.providers.users.model): nobody could sign in to Kami.',
                ($users === null ? '' : "point the provider of the guard {$panel['guard']} to {$users}, or ") . "set auth.providers.users.model to {$model}.",
                'users-and-roles.md#the-user-model',
            );
        }

        return $problems;
    }

    /**
     * @param  list<PanelReport>  $panels
     * @return list<Problem>
     */
    private function remoteAvatars(array $panels): array
    {
        return array_values(array_map(fn (array $panel): Problem => new Problem(
            Severity::Warning,
            "The panel {$panel['id']} draws avatars from ui-avatars.com, which receives the initials of your Users.",
            "add ->defaultAvatarProvider(InitialsAvatarProvider::class) to the panel {$panel['id']}.",
            'installation.md#installing-and-deploying',
        ), array_filter($panels, fn (array $panel): bool => $panel['remoteAvatars'])));
    }

    /**
     * A host's own page in place of Kami's, whose protection is then the host's.
     *
     * @param  list<PanelReport>  $panels
     * @param  'replacedLoginPage'|'replacedResetPage'  $key
     * @return list<Problem>
     */
    private function replacedPages(array $panels, string $key, string $page, string $method, string $duty, string $fix, string $anchor): array
    {
        return array_values(array_map(fn (array $panel): Problem => new Problem(
            Severity::Warning,
            "The panel {$panel['id']} replaces Kami's {$page} page with {$panel[$key]}: {$duty} is then yours.",
            "remove the class from ->{$method}() on the panel {$panel['id']}, or make its page {$fix}.",
            "security.md#{$anchor}",
        ), array_filter($panels, fn (array $panel): bool => $panel[$key] !== null)));
    }

    /**
     * Errors, so the rule that Users who may manage Users sign in with a second factor is never skipped by accident (ADR 0014).
     *
     * @param  list<PanelReport>  $panels
     * @return list<Problem>
     */
    private function replacedMfa(array $panels): array
    {
        return array_values(array_map(fn (array $panel): Problem => new Problem(
            Severity::Error,
            "The panel {$panel['id']} does not require Kami's MFA: Users who may manage Users sign in with a password alone.",
            "remove ->multiFactorAuthentication() and ->requiresMultiFactorAuthentication() from the panel {$panel['id']}: KamiPlugin sets them.",
            'security.md#mfa',
        ), array_filter($panels, fn (array $panel): bool => ! $panel['requiresKamiMfa'])));
    }

    /**
     * Blocking: `kami:install` would otherwise leave an admin nobody may sign in to.
     *
     * @param  list<PanelReport>  $panels
     * @return list<Problem>
     */
    private function mfaModels(array $panels): array
    {
        // A guard with no Eloquent model is already an error of userModels().
        $lacking = array_filter($panels, fn (array $panel): bool => ($model = $this->userModel($panel['guard'])) !== null && ! ($panel['storesMfa'])($model));

        return array_values(array_map(fn (array $panel): Problem => new Problem(
            Severity::Error,
            "The panel {$panel['id']} signs Users in with {$this->userModel($panel['guard'])}, which cannot store an authenticator: Users who may manage Users cannot set MFA up, nor sign in.",
            'add implements \\Filament\\Auth\\MultiFactor\\App\\Contracts\\HasAppAuthentication, \\Filament\\Auth\\MultiFactor\\App\\Contracts\\HasAppAuthenticationRecovery to the User model, and use \\Psyao\\Kami\\Admin\\HasKamiMfa; in its body.',
            'users-and-roles.md#the-user-model',
        ), $lacking));
    }

    /**
     * @param  list<PanelReport>  $panels
     * @return list<Problem>
     */
    private function managersWithoutMfa(array $panels): array
    {
        $problems = [];

        foreach ($panels as $panel) {
            $model = $this->userModel($panel['guard']);

            // The errors of replacedMfa(), mfaModels() and userModels() already cover these panels.
            if (! $panel['requiresKamiMfa'] || $model === null || ! is_a($model, Model::class, true) || ! ($panel['storesMfa'])($model)) {
                continue;
            }

            // Asked of each holder's model, so a host storing the authenticator on its own columns counts too; holders are few.
            $holders = DB::table('kami_role_user')->whereIn('role', Permissions::rolesGranting('kami.users.manage'))->select('user_id');
            $count = $model::query()->whereIn((new $model)->getKeyName(), $holders)->lazyById()
                ->reject(fn (Model $user): bool => $user instanceof Authenticatable && ($panel['hasMfa'])($user))
                ->count();

            if ($count > 0) {
                $problems[] = new Problem(
                    Severity::Warning,
                    "{$count} " . Str::plural('User', $count) . ' who may manage Users ' . ($count === 1 ? 'has' : 'have') . " no MFA set up: the panel {$panel['id']} sends " . ($count === 1 ? 'it' : 'them') . ' to set it up at the next sign-in.',
                    'nothing, once ' . ($count === 1 ? 'that User knows' : 'they know') . ' to bring an authenticator app, such as one on their phone.',
                    'users-and-roles.md#mfa',
                );
            }
        }

        return $problems;
    }

    /**
     * @param  list<PanelReport>  $panels
     * @return list<Problem>
     */
    private function strictAuthorization(array $panels): array
    {
        return array_values(array_map(fn (array $panel): Problem => new Problem(
            Severity::Warning,
            "The panel {$panel['id']} does not authorize strictly: a Resource without a policy opens to every User who signs in.",
            "add ->strictAuthorization() to the panel {$panel['id']}.",
            'users-and-roles.md#strict-authorization',
        ), array_filter($panels, fn (array $panel): bool => ! $panel['strict'])));
    }

    /**
     * A routable type that keeps `ContentType::render()` renders the view `kami.{handle}` (ADR 0009).
     *
     * @return list<Problem>
     */
    private function missingViews(): array
    {
        $viewless = array_filter(
            resolve(ContentTypes::class)->all(),
            fn (ContentType $type): bool => $type->isRoutable() && new ReflectionMethod($type, 'render')->getDeclaringClass()->getName() === ContentType::class && ! view()->exists("kami.{$type->handle()}"),
        );

        return array_values(array_map(fn (ContentType $type): Problem => new Problem(
            Severity::Warning,
            "The Content Type {$type->handle()} has no view kami.{$type->handle()}: its Entries cannot render.",
            "create resources/views/kami/{$type->handle()}.blade.php, or override render() in " . $type::class . '.',
            'routing-and-rendering.md#rendering',
        ), $viewless));
    }

    /**
     * A routable type whose `summary()` is the default with no RichText Field to read: its pages have no description but a Meta description.
     *
     * @return list<Problem>
     */
    private function undescribedTypes(): array
    {
        $undescribed = array_filter(
            resolve(ContentTypes::class)->all(),
            fn (ContentType $type): bool => $type->isRoutable() && new ReflectionMethod($type, 'summary')->getDeclaringClass()->getName() === ContentType::class
                && array_filter($type->flatFields(), fn (Field $field): bool => $field instanceof RichText) === [],
        );

        return array_values(array_map(fn (ContentType $type): Problem => new Problem(
            Severity::Warning,
            "The Content Type {$type->handle()} has no RichText Field for summary() to read: its pages have no description unless editors write one.",
            'override summary() in ' . $type::class . ' to return a Field, or add a RichText Field.',
            'seo.md#description',
        ), $undescribed));
    }

    /** @return list<string> */
    private function labelKeys(string $key, Field $field): array
    {
        $keys = ["{$key}.label"];

        if ($field instanceof Select) {
            array_push($keys, ...array_map(fn (string $option): string => "{$key}.options.{$option}", $field->optionKeys()));
        }

        if ($field instanceof StructuredField) {
            foreach ($field->fields() as $handle => $sub) {
                array_push($keys, ...$this->labelKeys("{$key}.fields.{$handle}", $sub));
            }
        }

        return $keys;
    }

    /**
     * One warning per language the admin may show, listing the label keys the admin humanises instead; help texts are optional.
     *
     * @return list<Problem>
     */
    private function missingLabels(): array
    {
        $keys = [];

        foreach (resolve(ContentTypes::class)->all() as $type) {
            array_push($keys, "{$type->handle()}.label", ...($type instanceof Singleton ? [] : ["{$type->handle()}.plural"]), ...array_map(fn (Layout $layout): string => "{$type->handle()}." . ($layout instanceof Tab ? 'tabs' : 'sections') . ".{$layout->handle}.label", $type->layouts()));

            foreach ($type->flatFields() as $field) {
                array_push($keys, ...$this->labelKeys("{$type->handle()}.fields.{$field->handle}", $field));
            }
        }

        // ponytail: the `default` Site's Locales only, as `Site::current()`; V1 multisite checks every Site's.
        $locales = array_unique([Config::string('app.locale'), ...Site::current()->locales]);
        $problems = [];

        foreach ($locales as $locale) {
            $missing = array_values(array_filter($keys, fn (string $key): bool => ! Lang::hasForLocale("kami-types.{$key}", $locale) && ! Lang::hasForLocale("kami::kami-types.{$key}", $locale)));

            if ($missing !== []) {
                $problems[] = new Problem(
                    Severity::Warning,
                    "lang/{$locale}/kami-types.php lacks " . count($missing) . ' label ' . Str::plural('key', count($missing)) . ': ' . implode(', ', $missing) . '.',
                    'add them: until then, the admin shows the humanised handle.',
                    'content-types.md#labels',
                );
            }
        }

        return $problems;
    }

    /**
     * Drift first: `kami:install` stops there, so what it would do to the Paths does not count yet, and a missing column would stop what comes next.
     *
     * @return list<Problem>
     */
    private function contentModel(): array
    {
        $drift = resolve(ContentModelDrift::class);
        $problems = $drift->problems();

        return $problems !== [] ? $problems : [...array_filter([$this->pathDrift()]), ...$drift->strandedValues()];
    }

    /** A Prefix changed or a Locale added since the last `kami:install`: the 200 rows still hold the old addresses (ADR 0008). */
    private function pathDrift(): ?Problem
    {
        $drift = resolve(SyncPaths::class)(write: false);

        return $drift['created'] + $drift['rewritten'] + $drift['deleted'] === 0 ? null : new Problem(
            Severity::Warning,
            "Paths do not follow the declarations yet: kami:install would create {$drift['created']}, rewrite {$drift['rewritten']} and delete {$drift['deleted']}.",
            'run php artisan kami:install, as every deploy does: until then, Entries answer at their former addresses.',
            'routing-and-rendering.md#after-a-deploy',
        );
    }

    /** Paths saved before a Locale or a segment Kami answers was declared: `ResolveLocale` or Kami's own route answers their address in the default Locale (ADR 0008). */
    private function hiddenPaths(): ?Problem
    {
        // ponytail: the `default` Site only, as `Site::current()`; V1 multisite checks every Site's.
        $site = Site::current();
        $segments = [...Path::RESERVED_SEGMENTS, ...array_slice($site->locales, 1)];
        // 200 rows only: a hidden Redirect or 410 has no slug to change, and no screen manages Redirects before M10.
        $hidden = Path::query()->where(['site' => $site->handle, 'locale' => $site->defaultLocale(), 'status' => 200])
            ->where(function (Builder $query) use ($segments): void {
                foreach ([...Path::RESERVED_PATHS, ...$segments] as $reserved) {
                    $query->orWhere('path', 'like', "{$reserved}%");
                }
            })
            ->orderBy('path')->get(['path'])->map(fn (Path $row): string => $row->path)
            // LIKE matches more: `_` in a Locale code reads as any character, and `media` matches `medias`.
            ->filter(fn (string $path): bool => Path::isReserved($path) || in_array(explode('/', $path)[0], $segments, true))->values();

        return $hidden->isEmpty() ? null : new Problem(
            Severity::Warning,
            "{$hidden->count()} " . Str::plural('Path', $hidden->count()) . ' of the default Locale ' . ($hidden->count() === 1 ? 'is' : 'are') . ' hidden by a Locale code or a segment Kami answers itself: ' . $hidden->take(5)->map(fn (string $path): string => "/{$path}")->implode(', ') . ($hidden->count() > 5 ? ', …' : '') . '.',
            "change their slug, or their type's Prefix: until then, they answer nowhere.",
            'routing-and-rendering.md#reserved-paths',
        );
    }

    /**
     * Content the declarations no longer reach: kept, never deleted, and answering nowhere (ADR 0006).
     *
     * @return list<Problem>
     */
    private function undeclaredContent(): array
    {
        // ponytail: the `default` Site only, as `Site::current()`; V1 multisite checks every Site's.
        $site = Site::current();
        $declared = [...$site->locales, '*'];
        $types = array_map(fn (ContentType $type): string => $type->handle(), array_values(resolve(ContentTypes::class)->all()));
        $tables = array_filter(['kami_translations', ...array_map(fn (string $type): string => "kami_type_{$type}", $types)], Schema::hasTable(...));
        $locales = collect($tables)
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->join('kami_entries', 'kami_entries.id', '=', "{$table}.entry_id")
                ->where('kami_entries.site', $site->handle)->whereNotIn("{$table}.locale", $declared)->distinct()->pluck("{$table}.locale")])
            ->filter(fn (Collection $locales): bool => $locales->isNotEmpty());
        $undeclared = DB::table('kami_entries')->where('site', $site->handle)->whereNotIn('type', $types)->distinct()->pluck('type');
        $problems = [];

        if ($locales->isNotEmpty()) {
            $problems[] = new Problem(
                Severity::Warning,
                "Rows of {$locales->keys()->implode(', ')} are in a Locale the {$site->handle} Site does not declare: {$locales->flatten()->unique()->sort()->implode(', ')}.",
                "declare it again in config/kami.php under sites.{$site->handle}.locales: until then they are kept and answer nowhere.",
                'multilingual.md#locales',
            );
        }

        if ($undeclared->isNotEmpty()) {
            $problems[] = new Problem(
                Severity::Warning,
                "Entries are of a type config/kami.php does not declare: {$undeclared->sort()->implode(', ')}.",
                'declare it again in config/kami.php under types: until then its Entries are kept and answer 404.',
                'content-types.md#declaring-a-type',
            );
        }

        return $problems;
    }

    /** @return list<Problem> */
    private function undeclaredRoles(): array
    {
        $undeclared = DB::table('kami_role_user')->whereNotIn('role', array_keys(Config::array('kami.roles')))->pluck('role');
        $count = $undeclared->count();

        return $count === 0 ? [] : [new Problem(
            Severity::Warning,
            "{$count} " . Str::plural('Role assignment', $count) . ' ' . ($count === 1 ? 'names' : 'name') . " a Role config/kami.php does not declare: {$undeclared->unique()->sort()->implode(', ')}.",
            'declare it in config/kami.php under roles, or delete its rows from kami_role_user: it grants nothing.',
            'users-and-roles.md#roles',
        )];
    }

    /** Never blocking: an upload or an import may leave alt text to be written later (ADR 0017). */
    private function missingAlt(): ?Problem
    {
        $count = Media::query()->missingAlt()->count();

        return $count === 0 ? null : new Problem(
            Severity::Warning,
            "{$count} " . Str::plural('image', $count) . ' ' . ($count === 1 ? 'has' : 'have') . ' no alt text.',
            "describe them in the admin's Media library, its Alt text missing filter lists them, or mark them decorative: until then, screen readers say nothing of them.",
            'media.md#alt-text-caption-and-credit',
        );
    }

    /**
     * @param  list<PanelReport>  $panels
     * @return list<Problem>
     */
    private function usersWithoutRole(array $panels): array
    {
        $problems = [];

        foreach ($panels as $panel) {
            $model = $this->userModel($panel['guard']);

            if ($model === null || ! is_a($model, KamiUser::class, true) || ! is_a($model, Model::class, true)) {
                continue;
            }

            // ponytail: one canAccessPanel() per User with no Role, read in chunks; a host with many customers waits; V1's host Role source (ADR 0014) lifts it.
            $count = $model::query()->whereNotIn((new $model)->getKeyName(), DB::table('kami_role_user')->select('user_id'))->lazyById()
                ->filter(fn (Model $user): bool => $user instanceof Authenticatable && ($panel['admits'])($user))
                ->count();

            if ($count > 0) {
                $problems[] = new Problem(
                    Severity::Info,
                    "{$count} " . Str::plural('User', $count) . " with no Kami Role can sign in to the panel {$panel['id']} and " . ($count === 1 ? 'sees' : 'see') . ' nothing of Kami.',
                    "nothing when they are the application's own staff; otherwise give them a Role, or turn them away in canAccessPanel().",
                    'users-and-roles.md#panel-access',
                );
            }
        }

        return $problems;
    }

    /** Vite's dev server origin joins the policy while `public/hot` exists (ADR 0018): in production, a leftover from a deploy. */
    private function hotFile(): ?Problem
    {
        return config('app.env') === 'production' && is_file($hot = Vite::hotFile()) ? new Problem(
            Severity::Error,
            "{$hot} exists in production: pages load their scripts from a Vite dev server, and the CSP allows its origin.",
            "delete {$hot}, and keep it out of what you deploy.",
            'security.md#vite-in-development',
        ) : null;
    }

    /**
     * Read from configuration, so the same on shared hosting and on a server: the command line sees no request, and a proxy in front changes nothing to it.
     *
     * @return list<Problem>
     */
    private function insecureTransport(): array
    {
        if (config('app.env') !== 'production') {
            return [];
        }

        $problems = [];
        $appUrl = config('app.url');
        /** @var array<string, array{url?: mixed}> $sites */
        $sites = Config::array('kami.sites', []);
        $urls = ['APP_URL' => $appUrl];

        foreach ($sites as $handle => $site) {
            // A Site's url defaults to APP_URL in config/kami.php: one fix, one warning.
            if (($site['url'] ?? null) !== $appUrl) {
                $urls["kami.sites.{$handle}.url"] = $site['url'] ?? null;
            }
        }

        foreach ($urls as $name => $url) {
            if (is_string($url) && str_starts_with(strtolower($url), 'http://')) {
                $problems[] = new Problem(
                    Severity::Warning,
                    "{$name} is {$url}: production travels in clear, the admin's session cookie and passwords included.",
                    "serve the site over HTTPS, then start {$name} with https://.",
                    'security.md#https-in-production',
                );
            }
        }

        // Left unset, the cookie is Secure only on a request Laravel sees as HTTPS: behind a shared host's proxy it trusts no proxy by default.
        if (config('session.secure') !== true) {
            $problems[] = new Problem(
                Severity::Warning,
                'The session cookie is not always Secure in production: behind a proxy Laravel does not trust, it travels without it.',
                'set SESSION_SECURE_COOKIE=true in .env.',
                'security.md#https-in-production',
            );
        }

        return $problems;
    }

    /**
     * @param  list<PanelReport>  $panels
     * @return list<Problem>
     */
    private function securityHeaders(array $panels): array
    {
        // bootstrap/app.php's middleware reaches the router when the HTTP kernel is built, which the command line never does.
        resolve(HttpKernel::class);
        $web = Route::getMiddlewareGroups()['web'] ?? [];
        $problems = is_array($web) && $this->sendsSecurityHeaders(array_values(array_filter($web, is_string(...)))) ? [] : [new Problem(
            Severity::Warning,
            "The web middleware group does not send Kami's security headers: your own pages go out without a CSP.",
            'append \\Psyao\\Kami\\Support\\SecurityHeaders::class to the web group in bootstrap/app.php ($middleware->web(append: [...])).',
            'security.md#your-own-pages',
        )];

        foreach ($panels as $panel) {
            if (! $this->sendsSecurityHeaders($panel['middleware'], 'admin')) {
                $problems[] = new Problem(
                    Severity::Warning,
                    "The panel {$panel['id']} does not send Kami's admin security headers: the admin goes out without its CSP, or with the front's, which blocks Filament's scripts.",
                    "add 'kami.security-headers:admin' to the ->middleware() of the panel {$panel['id']}.",
                    'security.md#the-admin',
                );
            }
        }

        return $problems;
    }

    /**
     * @param  list<string>  $middleware  by class or alias, with or without parameters
     * @param  ?string  $profile  the one required, or any
     */
    private function sendsSecurityHeaders(array $middleware, ?string $profile = null): bool
    {
        return array_any($middleware, fn (string $name): bool => in_array(Str::before($name, ':'), [SecurityHeaders::class, 'kami.security-headers'], true)
            && ($profile === null || Str::after($name, ':') === $profile));
    }

    /**
     * Each `kami.security.*` option that loosens ADR 0018's front policy (docs/guide/security.md).
     *
     * @return list<Problem>
     */
    private function weakeningOptions(): array
    {
        $problems = [];
        $weakening = fn (string $message, string $fix = 'remove it from config/kami.php unless you need it.'): Problem => new Problem(Severity::Warning, $message, $fix, 'security.md#weakening-options');

        /** @var array<string, list<string>|string> $csp */
        $csp = Config::array('kami.security.csp', []);

        foreach ($csp as $directive => $sources) {
            $sources = (array) $sources;

            foreach (array_filter($sources, fn (string $source): bool => str_starts_with($source, "'unsafe-")) as $source) {
                $problems[] = $weakening("kami.security.csp adds {$source} to {$directive}: injected markup can run code there.");
            }

            if ($sources !== [] && in_array("'none'", SecurityPolicy::FRONT[$directive] ?? [], true)) {
                $problems[] = $weakening("kami.security.csp opens {$directive}, which Kami sets to 'none'.");
            }
        }

        /** @var array<string, string|null> $headers */
        $headers = Config::array('kami.security.headers', []);

        // Header names are case-insensitive: `x-frame-options` is Kami's `X-Frame-Options`.
        $kamis = array_combine(array_map(strtolower(...), array_keys(SecurityPolicy::FRONT_HEADERS)), array_keys(SecurityPolicy::FRONT_HEADERS));

        foreach ($headers as $name => $value) {
            $name = $kamis[strtolower($name)] ?? null;

            if ($name === null) {
                continue;
            }

            if ($value === null) {
                $problems[] = $weakening("kami.security.headers omits {$name}.");
            } elseif ($value !== SecurityPolicy::FRONT_HEADERS[$name]) {
                $problems[] = $weakening("kami.security.headers replaces Kami's {$name} ({$value}).");
            }
        }

        if (is_string($uri = Config::get('kami.security.csp_report_uri')) && $uri !== '') {
            $problems[] = $weakening("kami.security.csp_report_uri sends violation reports, with page URLs and visitors' IP addresses, to {$uri}.", 'remove it once the violations are understood, or point it to a service you control.');
        }

        if (Config::boolean('kami.security.csp_report_only', false)) {
            $problems[] = $weakening('kami.security.csp_report_only is on: the CSP reports violations without blocking them.', 'turn it off once the policy is tested.');
        }

        return $problems;
    }

    /** A Conversion is generated once across PHP workers only under a lock they share (ADR 0017). */
    private function lockStore(): ?Problem
    {
        $store = Config::string('cache.default');
        $driver = config("cache.stores.{$store}.driver");

        return in_array($driver, ['array', 'null'], true) ? new Problem(
            Severity::Error,
            "The default cache store, {$store}, uses the {$driver} driver: its locks hold within one PHP worker, so two visitors can generate the same Conversion at once.",
            'set CACHE_STORE to file or database in .env, as the starter kit does.',
            'hosting.md#conversions',
        ) : null;
    }

    /** A page cache that cannot write stores nothing: every page then boots Laravel, and `Kami::remember()` caches nothing (ADR 0021). */
    private function pageCacheStorage(): ?Problem
    {
        // `Kami::remember()` keys its fragments on the manifest's version, page cache on or off.
        return ! PageCache::writable() ? new Problem(
            Severity::Warning,
            'Kami cannot write to storage/framework/kami/pages/: ' . (PageCache::enabled() ? 'every page boots Laravel, and ' : '') . 'Kami::remember() caches nothing.',
            'make storage/framework/ writable by PHP.',
            'page-cache.md#storage',
        ) : null;
    }

    /**
     * The indexable switch against the environment, `robots.txt` against what `kami:install` writes, the sitemap's freshness, and the host routes Kami's files would shadow (ADR 0027, ADR 0038).
     *
     * @return list<Problem>
     */
    private function searchEngines(): array
    {
        $env = Config::string('app.env');
        $indexable = (bool) config('kami.indexable');
        $robots = is_file($path = public_path('robots.txt')) ? File::get($path) : null;
        $problems = [];

        if ($env === 'production' && ! $indexable) {
            $problems[] = new Problem(Severity::Warning, 'The Site is not indexable in production: search engines are kept out of it.', 'set KAMI_INDEXABLE=true once the site is launched, then run kami:install.', 'seo.md#indexable-switch');
        } elseif ($env !== 'production' && $indexable) {
            $problems[] = new Problem(Severity::Warning, "The Site is indexable outside production ({$env}): search engines may index this copy.", 'set KAMI_INDEXABLE=false, or remove it, then run kami:install.', 'seo.md#indexable-switch');
        }

        if ($robots !== null && str_starts_with($robots, RobotsTxt::MARKER)) {
            if ($robots !== RobotsTxt::expected()) {
                $problems[] = new Problem(Severity::Warning, 'public/robots.txt differs from what kami:install writes.', 'run kami:install, or delete its first line to own the file.', 'seo.md#robotstxt');
            }
        } elseif ($indexable && $robots !== null && preg_match('/^\s*sitemap\s*:/im', $robots) !== 1) {
            $problems[] = new Problem(Severity::Warning, "public/robots.txt, the site's own, names no sitemap.", 'add a line Sitemap: ' . RobotsTxt::sitemapUrl() . ' to it.', 'seo.md#robotstxt');
        }

        if ($indexable && Sitemap::isStale() && Sitemap::staleSince() === null) {
            $problems[] = new Problem(Severity::Warning, 'No sitemap has been built for ' . Site::current()->url . ' yet.', 'run php artisan kami:sitemap; Kami::schedule() in routes/console.php has every Tick run it hourly.', 'seo.md#sitemap');
        } elseif ($indexable && ($since = Sitemap::staleSince()) !== null && $since < now()->getTimestamp() - 7200) {
            $problems[] = new Problem(Severity::Warning, 'The sitemap has been stale for more than two hours, since ' . date('Y-m-d H:i', $since) . ' UTC.', 'run php artisan kami:sitemap; Kami::schedule() in routes/console.php has every Tick run it hourly.', 'seo.md#sitemap');
        }

        foreach (['robots.txt', 'sitemap.xml'] as $file) {
            if (RobotsTxt::hostRouteAnswers("/{$file}")) {
                $problems[] = new Problem(Severity::Warning, "A route of the application answers /{$file}: Kami writes no public/{$file}, as the file would shadow the route.", $file === 'robots.txt' ? 'make sure it disallows the Kami panel and names the sitemap, or remove the route so kami:install writes the file.' : 'list the files Kami::sitemapUrls() returns in its index, or remove the route.', 'seo.md#host-routes');
            }
        }

        return $problems;
    }

    /** What only a running site shows, a web request or a Tick, is information on a developer's machine and in CI, which have neither. */
    private function runningSiteSeverity(): Severity
    {
        return in_array(Config::string('app.env'), ['local', 'testing'], true) ? Severity::Info : Severity::Warning;
    }

    /**
     * The web's PHP, from the snapshot the last web or visit Tick wrote, against the same requirements as the command line's.
     *
     * @return list<Problem>
     */
    private function webPhp(): array
    {
        if (($last = WebEnvironment::last()) === null) {
            return [new Problem(
                $this->runningSiteSeverity(),
                "No web request has written the web PHP's snapshot yet: kami:doctor sees only the command line's PHP, which may differ.",
                'visit the site, or call its tick URL, then run kami:doctor again.',
                'deployment.md#the-web-php',
            )];
        }

        $web = $last['snapshot'];
        $extensions = $web['extensions'];
        $doctor = new self($web['php'], fn (string $extension): bool => in_array(strtolower($extension), $extensions, true));

        return array_map(
            fn (Problem $problem): Problem => new Problem($problem->severity, "On the web ({$web['sapi']}): {$problem->message}", $problem->fix, $problem->guide),
            array_values(array_filter([$doctor->phpVersion(), ...$doctor->extensions(), $doctor->imageExtension()])),
        );
    }

    /**
     * Ticks that stopped, ran over their budget or cannot run every task, and tasks late or failing (ADR 0020).
     *
     * @return list<Problem>
     */
    private function scheduler(): array
    {
        $ticks = array_filter(array_combine(Tick::TRIGGERS, array_map(Tick::last(...), Tick::TRIGGERS)));
        $dayAgo = Date::now()->subDay()->getTimestamp();
        $recent = fn (string $trigger): bool => ($ticks[$trigger]['at'] ?? 0) > $dayAgo;
        $events = resolve(Schedule::class)->events();
        $guide = self::SCHEDULER_GUIDE;
        $problems = [];
        $byAge = $ticks;
        uasort($byAge, fn (array $a, array $b): int => $a['at'] <=> $b['at']);
        $latest = array_key_last($byAge);
        $last = end($byAge);

        if (! $recent('cli') && ! $recent('web') && ! $recent('visit')) {
            $problems[] = new Problem($this->runningSiteSeverity(), ($ticks === [] ? 'No Tick has run yet' : 'No Tick has run for 24 hours') . ': Scheduled tasks and queued jobs wait.', "have the host's planner call the tick URL (php artisan kami:tick:url), or cron run php artisan kami:tick every minute.", $guide);
        }

        if (blank(config('kami.tick.token')) && ! $recent('cli')) {
            $problems[] = new Problem($this->runningSiteSeverity(), 'KAMI_TICK_TOKEN is empty and kami:tick has not run for 24 hours: only visits start a Tick.', "run php artisan kami:tick:url and have the host's planner call the URL it prints, or cron run php artisan kami:tick.", 'scheduler.md#on-shared-hosting');
        }

        if ($last !== false && $last['result'] === 'FAILED') {
            $problems[] = new Problem(Severity::Warning, "The last Tick, from the {$latest} trigger at " . date('Y-m-d H:i', $last['at']) . ' UTC, failed.', 'read the error in the log: a Tick fails when a task fails, and when it cannot start, before kami:install or with the database down.', $guide);
        }

        foreach ($ticks as $trigger => $tick) {
            // A trigger that stopped, such as visits once cron runs, leaves its last file behind.
            if ($tick['at'] > $dayAgo && $tick['budget'] !== null && $tick['duration_ms'] > $tick['budget'] * 1000) {
                $problems[] = new Problem(Severity::Warning, sprintf('The last %s Tick took %.1f s, over its budget of %d s: a task or a job ran on past it.', $trigger, $tick['duration_ms'] / 1000, $tick['budget']), 'keep each task and job short: long work dispatches one job per chunk.', $guide);
            }
        }

        if (! array_any($events, fn (Event $event): bool => $event->description === 'kami:page-cache:prune')) {
            $problems[] = new Problem(Severity::Warning, "Kami::schedule() is not called: Kami's tasks, the sitemap's rebuild among them, never run.", 'add Kami::schedule(); to routes/console.php.', 'scheduler.md#declaring-kamis-tasks');
        }

        if (($exec = array_filter($events, Tick::isExec(...))) !== [] && ! $recent('cli')) {
            $problems[] = new Problem(Severity::Warning, 'kami:tick has not run for 24 hours, and only it runs exec() tasks: ' . implode(', ', array_map(Tick::describe(...), $exec)) . '.', 'run php artisan kami:tick from cron, or schedule an Artisan command or a closure in its place.', $guide);
        }

        return [...$problems, ...$this->tasks($events)];
    }

    /**
     * @param  array<Event>  $events
     * @return list<Problem>
     */
    private function tasks(array $events): array
    {
        $runs = rescue(fn () => DB::table('kami_task_runs')->get()->keyBy('task'), null, report: false);

        if ($runs === null) {
            return [];
        }

        $hourAgo = Date::now()->subHour();
        $paused = Tick::paused();
        $late = $failed = [];

        foreach ($events as $event) {
            $run = $runs->get($event->mutexName());

            if (! is_string($lastRun = $run?->last_run_at)) {
                continue;
            }

            // A `runInBackground()` task is `succeeded` as it starts: Laravel tells nothing of its end.
            if ($run->last_status === 'failed' || ($run->last_status === 'running' && CarbonImmutable::parse($lastRun, 'UTC') < $hourAgo)) {
                $failed[] = Tick::describe($event) . ($run->last_status === 'running' ? ' (did not finish)' : '');
            } elseif (($missed = Tick::missedSince($event, $lastRun)) instanceof CarbonImmutable && $missed < $hourAgo && rescue(fn (): bool => Tick::wouldRun($event, $paused), false, report: false)) {
                $late[] = Tick::describe($event) . ', due ' . $missed->utc()->format('Y-m-d H:i') . ' UTC';
            }
        }

        return array_values(array_filter([
            $late === [] ? null : new Problem(Severity::Warning, 'Scheduled tasks over an hour late: ' . implode('; ', array_unique($late)) . '.', 'check that Ticks run: a Tick starts no task once its budget is spent, so a long task delays the next.', self::SCHEDULER_GUIDE),
            $failed === [] ? null : new Problem(Severity::Warning, 'Scheduled tasks that failed on their last run: ' . implode(', ', array_unique($failed)) . '.', 'read the error in the log: each runs again at its next due time.', self::SCHEDULER_GUIDE),
        ]));
    }

    /**
     * Failed jobs, and jobs left waiting on a `database` default connection, which only Ticks work through on shared hosting.
     *
     * @return list<Problem>
     */
    private function queue(): array
    {
        $failer = resolve(FailedJobProviderInterface::class);
        // Every failer but DynamoDB's counts: that one throws, read as none, as does a missing `failed_jobs`.
        $failed = rescue(fn (): int => $failer->count(), 0, report: false);
        // A reserved job is being worked on, or comes back after `retry_after`.
        $oldest = rescue(fn (): mixed => self::queuedJobs()?->whereNull('reserved_at')->min('available_at'), null, report: false);

        return array_values(array_filter([
            $failed === 0 ? null : new Problem(Severity::Warning, $failed === 1 ? 'A queued job failed.' : "{$failed} queued jobs failed.", 'list them with php artisan queue:failed, then retry them with queue:retry all or delete them with queue:flush.', 'scheduler.md#the-queue'),
            is_numeric($oldest) && (int) $oldest < Date::now()->subHour()->getTimestamp()
                ? new Problem(Severity::Warning, 'The oldest queued job has waited since ' . date('Y-m-d H:i', (int) $oldest) . ' UTC.', 'check that Ticks run: each works through the default queue with the time its tasks leave. A job on another queue waits for a worker of your own.', 'scheduler.md#the-queue')
                : null,
        ]));
    }

    /** The default queue connection's jobs, when it uses the `database` driver, which every Tick works through; null for another driver. */
    public static function queuedJobs(): ?QueryBuilder
    {
        $connection = 'queue.connections.' . Config::string('queue.default');

        return config("{$connection}.driver") === 'database'
            ? DB::connection(is_string($database = config("{$connection}.connection")) ? $database : null)->table(Config::string("{$connection}.table", 'jobs'))
            : null;
    }

    /** Originals keep their metadata: a disk the web server serves bypasses the stripping every Conversion goes through. */
    private function exposedOriginals(): ?Problem
    {
        $refusal = PublicMedia::exposedOriginalsRefusal();

        return $refusal === null ? null : new Problem(
            Severity::Error,
            $refusal,
            'set kami.media.originals_disk to a private disk, such as local, then move its media/ files there.',
            'hosting.md#conversions',
        );
    }

    /** `kami:install`'s link, then the headers the server sends on it, checked over HTTP: the command line cannot tell Apache from nginx. */
    private function publicMedia(): ?Problem
    {
        if (($refusal = PublicMedia::localDiskRefusal()) !== null) {
            return new Problem(Severity::Error, $refusal, 'set kami.media.public_disk to a local disk, such as public.', 'hosting.md#conversions');
        }

        if (! PublicMedia::isLinked()) {
            return new Problem(
                Severity::Error,
                "public/media does not link to the public disk's media directory (" . PublicMedia::target() . '): every Conversion goes through PHP.',
                'run php artisan kami:install, which links it, as every deploy does.',
                'hosting.md#conversions',
            );
        }

        return $this->conversionHeaders();
    }

    /** An SVG, so one request shows every header Kami's `.htaccess` sets; written for the check and deleted after it. */
    private function conversionHeaders(): ?Problem
    {
        $disk = Storage::disk(Config::string('kami.media.public_disk'));
        $base = rtrim(Site::current()->url, '/');
        $url = "{$base}/media/kami-doctor.svg";
        $disk->put('media/kami-doctor.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        try {
            $response = Http::connectTimeout(3)->timeout(5)->get($url);
        } catch (ConnectionException $exception) {
            return new Problem(
                Severity::Info,
                "The headers of public/media were not checked: {$base} cannot be reached from here ({$exception->getMessage()}).",
                'run kami:doctor where the site\'s URL can be reached, or compare its headers with the guide by hand.',
                'hosting.md#nginx',
            );
        } finally {
            $disk->delete('media/kami-doctor.svg');
        }

        // A staging site behind a password: as unreachable as one with no server.
        if (in_array($response->status(), [401, 403], true)) {
            return new Problem(
                Severity::Info,
                "The headers of public/media were not checked: {$url} answers {$response->status()}, behind a password or a firewall.",
                'compare its headers with the guide by hand.',
                'hosting.md#nginx',
            );
        }

        // On a developer's machine, such as Herd's nginx, which no deploy configures: the server's own run decides.
        $severity = app()->isLocal() ? Severity::Info : Severity::Warning;

        if (! $response->successful()) {
            return new Problem(
                $severity,
                "{$url} answers {$response->status()}: the server does not serve public/media.",
                'serve the files under public/media as they are, and hand a missing one to index.php.',
                'hosting.md#nginx',
            );
        }

        // In any order: Symfony sorts Cache-Control's directives, nginx keeps them as written.
        $directives = fn (string $value): array => collect(preg_split('/\s*[,;]\s*/', strtolower(trim($value))) ?: [])->sort()->values()->all();
        $missing = array_filter([...PublicMedia::HEADERS, ...PublicMedia::SVG_HEADERS], fn (string $value, string $name): bool => $directives($response->header($name)) !== $directives($value), ARRAY_FILTER_USE_BOTH);

        return $missing === [] ? null : new Problem(
            $severity,
            'Files under public/media go out without ' . implode(', ', array_map(fn (string $name, string $value): string => "{$name}: {$value}", array_keys($missing), $missing)) . '.',
            'send them from the server\'s configuration, as public/media/.htaccess does on Apache.',
            'hosting.md#nginx',
        );
    }
}
