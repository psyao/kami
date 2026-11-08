<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Contracts\Image\Driver;
use Illuminate\Http\UploadedFile;
use Illuminate\Image\Drivers\GdDriver;
use Illuminate\Image\Drivers\ImagickDriver;
use Illuminate\Image\ImageException;
use Illuminate\Image\ImagePipeline;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
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

/** A directory of the test's own, as parallel processes share the skeleton's `storage/`. Random, not `uniqid()`: two processes reading the same microsecond would share it, and one's cleanup delete the other's files (#338). */
function temporaryPath(): string
{
    return sys_get_temp_dir() . '/kami-' . bin2hex(random_bytes(8));
}

/** The authenticator secret of the Users `withMfa()` sets up. */
const MFA_SECRET = 'JBSWY3DPEHPK3PXP';

/** MFA set up for `$user`, with these recovery codes in clear, or a manager is sent to set it up before any admin page. */
function withMfa(User $user, ?array $codes = null): User
{
    DB::table('kami_user_mfa')->insert([
        'user_id' => $user->getKey(),
        'secret' => Crypt::encryptString(MFA_SECRET),
        'recovery_codes' => $codes === null ? null : Crypt::encryptString(json_encode(array_map(Hash::make(...), $codes))),
    ]);

    return $user;
}

/** The admin's dashboard, signed in as an administrator. Filament's theme drops its transitions under reduced motion, so axe never measures a notification mid-fade. */
function signIn(): mixed
{
    // Without model events: a fixture, whose own `kami.users.created` row no test reads.
    $user = User::withoutEvents(fn () => User::forceCreate(['name' => 'Ada Lovelace', 'email' => 'ada@example.test', 'password' => Hash::make('secret-password')]));
    DB::table('kami_role_user')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->getKey(), 'role' => 'administrator']);
    withMfa($user);

    return passSignIn(visit('/admin/login', ['reducedMotion' => 'reduce']), 'ada@example.test')->assertSee('Dashboard');
}

/** The sign-in page's first step, the email and password; a User with MFA is then asked a code, a manager without it sent to set it up. */
function signInPassword(mixed $page, string $email): mixed
{
    // Typed before the password field's lazy component loads or after, the password is kept (`Browser/SignInTest`, #128).
    // Not before Alpine starts, which empties the email: after a redirect, such as signing out, the page may still be loading (#369, #396).
    return livewireStarted($page)
        ->fill('[id="form.email"]', $email)
        ->fill('[id="form.password"]', 'secret-password')
        ->press('internal:role=button[name="Sign in"s]');
}

/**
 * The page once its document has loaded and Livewire has started its components, so a field filled now is not emptied by Alpine and a button pressed now acts.
 *
 * ponytail: stands in for `waitForEvent('load')`, which sends nothing in pest-plugin-browser 5.2.0 (`Page::waitForLoadState()` never runs the client's generator, #396); #420 drops it once that is fixed upstream.
 */
function livewireStarted(mixed $page): mixed
{
    $page->script(<<<'JS'
        () => new Promise((resolve, reject) => {
            const until = Date.now() + 5000;
            const started = () => document.readyState === 'complete' && [...document.querySelectorAll('[wire\\:id]')].every((element) => element.__livewire)
                ? resolve()
                : Date.now() > until ? reject(new Error('Livewire has not started after 5 seconds.')) : setTimeout(started, 20);
            started();
        })
        JS);

    return $page;
}

/** Through the sign-in page: the password, then the authenticator's current code. A second sign-in within the same 30 seconds is refused: Filament accepts each code once. */
function passSignIn(mixed $page, string $email, string $secret = MFA_SECRET): mixed
{
    // The last digit submits the form, so the field is waited for first: Pest retries a `fill` that took over a second, and the retry finds the page signed in (#369).
    return signInPassword($page, $email)->assertSee('Enter the 6-digit code from the authenticator app')->fill('[id="multiFactorChallengeForm.app.code"]', (new Google2FA)->getCurrentOtp($secret));
}

/** Signs in a new User holding the Role, or these Permissions under a Role of their own; the panel admits them, as in the local environment. */
function signInWith(string | array $role = 'administrator'): User
{
    if (is_array($role)) {
        config(['kami.roles.custom' => $role]);
        $role = 'custom';
    }

    // Without model events: a fixture, whose own `kami.users.created` row no test reads.
    $user = User::withoutEvents(fn () => User::forceCreate(['name' => 'Ada Lovelace', 'email' => uniqid() . '@example.test', 'password' => 'secret']));
    DB::table('kami_role_user')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->getKey(), 'role' => $role]);
    withMfa($user);
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

/** How many notifications with that title the panel shows once every one sent so far reaches it: the panel keys them by id, so one sent again under the same id replaces the first. */
function shownNotifications(string $title): int
{
    $mailbox = resolve(NotificationMailbox::class);
    $mailbox->flush();

    return collect($mailbox->pull())->where('title', $title)->keyBy('id')->count();
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

/** A file as the browser sends it, named as the editor's own. */
function uploadedFixture(string $fixture, ?string $name = null): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name ?? basename($fixture), (string) file_get_contents(fixture("media/{$fixture}")));
}

/** A User for an audit test, without the `kami.users.created` row of their own creation, which no such test reads. */
function auditUser(): User
{
    return User::withoutEvents(fn () => User::forceCreate(['name' => 'Ada Lovelace', 'email' => uniqid() . '@example.test', 'password' => 'secret']));
}

/**
 * The Audit log's rows, oldest first, as a site's own query would read them. Contexts by key: MySQL's JSON keeps no order.
 *
 * @return list<array<string, mixed>>
 */
function auditEvents(): array
{
    return DB::table('kami_audit_events')->orderBy('occurred_at')->orderBy('id')->get()
        ->map(function (object $row): array {
            /** @var array<string, mixed> $context */
            $context = json_decode((string) $row->context, true);
            ksort($context);

            return [...(array) $row, 'context' => $context];
        })
        ->all();
}
