<?php

namespace Psyao\Kami\Support\Internal\Doctor;

use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The closed list of checks behind `kami:doctor`; later milestones add theirs here.
 *
 * @internal
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
        return $this->environment();
    }

    /**
     * The environment errors, which stop `kami:install` before it migrates.
     *
     * @return list<Problem>
     */
    public function blocking(): array
    {
        return array_values(array_filter($this->environment(), fn (Problem $problem): bool => $problem->severity === Severity::Error));
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
        } catch (Throwable $exception) {
            $name = DB::getDefaultConnection();
            $driver = config("database.connections.{$name}.driver");
            $extension = match ($driver) {
                'mysql', 'mariadb' => 'the PHP extension pdo_mysql',
                'pgsql' => 'the PHP extension pdo_pgsql',
                'sqlite' => 'the PHP extension pdo_sqlite',
                default => 'its PDO driver',
            };

            return new Problem(
                Severity::Error,
                "Kami cannot connect to the database {$name}: {$exception->getMessage()}",
                "check the DB_* settings in .env and that {$extension} is loaded.",
                'installation.md#database',
            );
        }
    }
}
