<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Contracts\Image\Driver;
use Illuminate\Image\Drivers\GdDriver;
use Illuminate\Image\Drivers\ImagickDriver;
use Illuminate\Image\ImageException;
use Illuminate\Image\ImagePipeline;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Psyao\Kami\Facades\Kami;
use Psyao\Kami\Media\Internal\PublicMedia;
use Psyao\Kami\Tests\Install\InstallTestCase;
use Psyao\Kami\Tests\TestCase;
use Symfony\Component\Finder\SplFileInfo;
use Workbench\App\Models\User;

// One directory per suite: `Install/` boots without the Workbench.
pest()->extend(TestCase::class)->in('Kami', 'Volume');
// The browser loads Filament's scripts and Kami's stylesheet from the skeleton's public directory, as on a Site after `kami:install`.
// For every test of the suite, whichever runs first: the browser's server may keep the first test's application.
pest()->extend(TestCase::class)->beforeEach(function (): void {
    // Filament opens its panel to a User that is not a `FilamentUser` in the local environment only.
    config(['app.env' => 'local']);
    // The suite's array sessions end with each request: signing in needs one that lasts.
    config(['session.driver' => 'file']);
    Kami::routes();
    $this->artisan('filament:assets');
})->in('Browser');
pest()->extend(InstallTestCase::class)->in('Install');

/** A directory of the test's own, as parallel processes share the skeleton's `storage/`. */
function temporaryPath(): string
{
    return sys_get_temp_dir() . '/kami-' . uniqid();
}

/** The admin's dashboard, signed in as an administrator. Filament's theme drops its transitions under reduced motion, so axe never measures a notification mid-fade. */
function signIn(): mixed
{
    $user = User::forceCreate(['name' => 'Ada Lovelace', 'email' => 'ada@example.test', 'password' => Hash::make('secret-password')]);
    DB::table('kami_role_user')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->getKey(), 'role' => 'administrator']);

    $page = visit('/admin/login', ['reducedMotion' => 'reduce']);

    // Filament loads the password field's Alpine component after the page (`x-load`, `x-ignore` until it arrives):
    // its `wire:model` then resets the field to the empty Livewire state, wiping a password typed before.
    // The plugin times the script out after 5 seconds, failing here if a component never loads.
    // ponytail: tied to Filament's `x-load`/`x-ignore` markup; V1 at the latest, when pest-plugin-browser exposes `waitForFunction`
    // or Filament keeps typed input.
    $page->script("new Promise(function wait(resolve) { document.querySelector('[x-load][x-ignore]') ? requestAnimationFrame(() => wait(resolve)) : resolve(); })");

    return $page
        ->fill('[id="form.email"]', 'ada@example.test')
        ->fill('[id="form.password"]', 'secret-password')
        ->press('internal:role=button[name="Sign in"s]')
        ->assertSee('Dashboard');
}

/** Signs in a new User holding the Role, or these Permissions under a Role of their own; the panel admits them, as in the local environment. */
function signInWith(string | array $role = 'administrator'): User
{
    if (is_array($role)) {
        config(['kami.roles.custom' => $role]);
        $role = 'custom';
    }

    $user = User::forceCreate(['name' => 'Ada Lovelace', 'email' => uniqid() . '@example.test', 'password' => 'secret']);
    DB::table('kami_role_user')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->getKey(), 'role' => $role]);
    config(['app.env' => 'local']);
    test()->actingAs($user);

    return $user;
}

/** A page of the type's Entries in the admin, for a User holding the Role, or these Permissions under a Role of their own. */
function entriesPage(string $page, string | array $role = 'administrator', array $parameters = [], ?string $type = 'article'): Testable
{
    signInWith($role);
    Filament::setCurrentPanel('admin');
    Filament::setCurrentResourceConfigurationKey($type);

    return Livewire::test($page, $parameters);
}

/**
 * Conversions as `kami:install` leaves a site, for a test that expects `kami:doctor` clean: a shared lock store, `public/`
 * and the public disk the test's own, linked, and served with Kami's headers. A test changes the answer through `$this->served`.
 */
function withConversionsServed(): void
{
    config(['cache.default' => 'file', 'filesystems.disks.public.root' => temporaryPath()]);
    app()->usePublicPath(temporaryPath());
    File::ensureDirectoryExists(public_path());
    PublicMedia::install();
    Http::preventStrayRequests();
    // A second Http::fake() would come after this one.
    test()->served = null;
    Http::fake(['*/media/kami-doctor.svg' => fn () => value(test()->served ?? Http::response('<svg/>', 200, [
        'X-Content-Type-Options' => 'nosniff',
        'Cache-Control' => 'public, max-age=31536000, immutable',
        'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:",
    ]))]);
}

/** Both Image drivers failing to write these formats, as a library without their encoder, or a generation out of memory, does; none restores them. */
function failingToEncode(string ...$formats): void
{
    Image::forgetDrivers();

    foreach (['gd' => GdDriver::class, 'imagick' => ImagickDriver::class] as $name => $class) {
        Image::extend($name, fn (): Driver => new class(new $class, $formats) implements Driver
        {
            /** @param list<string> $formats */
            public function __construct(private readonly Driver $driver, private readonly array $formats) {}

            public function process(string $contents, ImagePipeline $pipeline): string
            {
                return in_array($pipeline->output->format, $this->formats, true)
                    ? throw new ImageException("No encoder for {$pipeline->output->format}.")
                    : $this->driver->process($contents, $pipeline);
            }

            public function dimensions(string $contents): array
            {
                return $this->driver->dimensions($contents);
            }

            public function dominantColor(string $contents): string
            {
                return $this->driver->dominantColor($contents);
            }

            public function transformUsing(string $transformation, callable $callback): static
            {
                $this->driver->transformUsing($transformation, $callback);

                return $this;
            }
        });
    }
}

/** `kami:install` as a deploy runs it, with the first administrator's inputs. */
function install(): PendingCommand
{
    return test()->artisan('kami:install', ['--no-interaction' => true, '--email' => 'ada@example.test', '--name' => 'Ada']);
}

/**
 * The anchors of a Markdown file's headings, as GitHub and the Boost resources link them.
 *
 * @return Collection<int, string>
 */
function headingAnchors(string $file): Collection
{
    return Str::of(File::get(dirname(__DIR__) . "/{$file}"))
        ->matchAll('/^#+ (.+)$/m')
        ->map(fn (string $heading): string => Str::slug($heading));
}

/**
 * Every class, enum, interface and trait under `src/`.
 *
 * @return Collection<int, ReflectionClass<object>>
 */
function kamiClasses(): Collection
{
    return collect(File::allFiles(__DIR__ . '/../src'))
        ->map(fn (SplFileInfo $file): ReflectionClass => new ReflectionClass('Psyao\\Kami\\' . str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname())));
}

/** @param ReflectionClass<object> $class */
function isInternal(ReflectionClass $class): bool
{
    return str_contains($class->getNamespaceName() . '\\', '\\Internal\\');
}

function isMarkedInternal(ReflectionClass | ReflectionClassConstant | ReflectionMethod | ReflectionProperty $element): bool
{
    return (bool) preg_match('/@internal\b/', (string) $element->getDocComment());
}

/**
 * The classes a site may call or name (ADR 0010).
 *
 * @return Collection<int, ReflectionClass<object>>
 */
function publicClasses(): Collection
{
    return kamiClasses()->reject(isInternal(...))->values();
}
