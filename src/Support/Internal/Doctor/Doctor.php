<?php

declare(strict_types=1);

namespace Psyao\Kami\Support\Internal\Doctor;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\SQLiteDatabaseDoesNotExistException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Psyao\Kami\Support\Internal\Install\PluginRegistration;
use Psyao\Kami\Users\KamiUser;
use Throwable;

/**
 * The closed list of checks behind `kami:doctor`; later milestones add theirs here.
 *
 * @internal
 *
 * @phpstan-type PanelReport array{id: string, guard: string, strict: bool, remoteAvatars: bool, admits: Closure(Authenticatable): bool}
 */
final readonly class Doctor
{
    /** The lowest PHP version Kami runs on, as `composer.json` requires it. */
    public const string PHP_FLOOR = '8.5.0';

    /** What Kami and its dependencies require at run time, as `composer check-platform-reqs --no-dev` lists it. */
    public const array EXTENSIONS = [
        'ctype', 'dom', 'fileinfo', 'filter', 'hash', 'iconv', 'intl', 'json', 'libxml',
        'mbstring', 'openssl', 'pcre', 'session', 'tokenizer', 'xmlreader', 'zip',
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

        return [
            ...$this->environment(),
            ...$this->userModels($panels),
            ...$this->remoteAvatars($panels),
            ...$this->strictAuthorization($panels),
            ...($assignments ? [...$this->undeclaredRoles(), ...$this->usersWithoutRole($panels)] : []),
        ];
    }

    /**
     * The errors that stop `kami:install` before it migrates.
     *
     * @return list<Problem>
     */
    public function blocking(): array
    {
        return array_values(array_filter([...$this->environment(), ...$this->userModels($this->panels())], fn (Problem $problem): bool => $problem->severity === Severity::Error));
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

            // ponytail: one canAccessPanel() per User with no Role, read in chunks; a host with many customers waits, a SQL rule would need the host's.
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
}
