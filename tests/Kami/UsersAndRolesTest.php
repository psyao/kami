<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User as PlainUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Support\Internal\Doctor\Doctor;
use Psyao\Kami\Users\Actions\AssignRoles;
use Psyao\Kami\Users\Events\RolesAssigned;
use Workbench\App\Content\Article;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

function userWithRoles(string ...$roles): User
{
    $user = User::forceCreate(['name' => 'Ada Lovelace', 'email' => uniqid() . '@example.test', 'password' => 'secret']);
    DB::table('kami_role_user')->insert(array_map(fn (string $role) => ['id' => (string) Str::ulid(), 'user_id' => $user->getKey(), 'role' => $role], $roles));

    return $user;
}

/** @return list<string> */
function kamiPermissions(): array
{
    return collect(Gate::abilities())->keys()->filter(fn (string $ability) => str_starts_with($ability, 'kami.'))->values()->all();
}

/** A Role granting only these Permissions, as a site declares it. */
function declareRole(string $handle, string ...$permissions): void
{
    config(["kami.roles.{$handle}" => $permissions]);
}

function frenchArticle(string $status): Entry
{
    return resolve(SaveEntry::class)->handle(Article::class, ['title' => uniqid('Hello '), 'status' => $status, 'author' => 'Ada'], locale: 'fr');
}

it('names one Permission per action on each declared type, the Media library\'s, kami.health.view and kami.users.manage', function () {
    expect(kamiPermissions())->toEqualCanonicalizing([
        ...collect(['article', 'page', 'label', 'tag', 'project', 'home', 'testimonial', 'footer', 'site_settings'])->crossJoin(['view', 'create', 'update', 'reorder', 'publish', 'delete', 'restore', 'force-delete', 'share-preview'])->map(fn (array $pair) => "kami.entries.{$pair[0]}.{$pair[1]}"),
        'kami.media.view',
        'kami.media.create',
        'kami.media.update',
        'kami.media.delete',
        'kami.health.view',
        'kami.users.manage',
    ]);
});

it('grants an administrator every Kami Permission and a User with no Role none', function () {
    $administrator = userWithRoles('administrator');
    $nobody = userWithRoles();

    foreach (kamiPermissions() as $permission) {
        expect(Gate::forUser($administrator)->allows($permission))->toBeTrue($permission)
            ->and(Gate::forUser($nobody)->allows($permission))->toBeFalse($permission);
    }
});

it('denies a guest and a User model without KamiUser', function () {
    $plain = PlainUser::forceCreate(['name' => 'Plain', 'email' => 'plain@example.test', 'password' => 'secret']);

    expect(Gate::allows('kami.users.manage'))->toBeFalse()
        ->and(Gate::forUser($plain)->allows('kami.users.manage'))->toBeFalse();
});

it('expands wildcards and joins the Permissions of every Role a User holds', function () {
    declareRole('reader', 'kami.entries.*.view');
    declareRole('writer', 'kami.entries.article.update');
    $user = userWithRoles('reader', 'writer');

    expect(Gate::forUser($user)->allows('kami.entries.page.view'))->toBeTrue()
        ->and(Gate::forUser($user)->allows('kami.entries.article.update'))->toBeTrue()
        ->and(Gate::forUser($user)->allows('kami.entries.page.update'))->toBeFalse()
        ->and(Gate::forUser($user)->allows('kami.users.manage'))->toBeFalse();
});

it('grants nothing through an undeclared Role', function () {
    expect(Gate::forUser(userWithRoles('ghost'))->allows('kami.entries.article.view'))->toBeFalse();
});

it('leaves the host\'s own abilities alone, even for an administrator', function () {
    Gate::define('manage-billing', fn (User $user) => false);
    $administrator = userWithRoles('administrator');

    expect(Gate::forUser($administrator)->allows('manage-billing'))->toBeFalse()
        ->and(Gate::forUser($administrator)->allows('kami.anything'))->toBeFalse()
        ->and(Gate::forUser($administrator)->allows('undeclared-ability'))->toBeFalse();
});

it('reads a User\'s Roles once', function () {
    $user = userWithRoles('administrator');
    DB::enableQueryLog();

    foreach (kamiPermissions() as $permission) {
        Gate::forUser($user)->allows($permission);
    }

    expect(DB::getQueryLog())->toHaveCount(1);
});

it('drops a User\'s assignments with the User', function () {
    $user = userWithRoles('editor');

    $user->delete();

    expect(DB::table('kami_role_user')->count())->toBe(0);
});

it('maps Filament\'s policy methods to the type\'s Permissions', function (string $ability, Closure $arguments, string $permission) {
    declareRole('only', $permission);
    $user = userWithRoles('only');
    $entry = frenchArticle('draft');

    expect(Gate::forUser($user)->allows($ability, $arguments($entry)))->toBeTrue()
        ->and(Gate::forUser(userWithRoles())->allows($ability, $arguments($entry)))->toBeFalse();
})->with([
    'viewAny' => ['viewAny', fn () => [Entry::class, 'article'], 'kami.entries.article.view'],
    'view' => ['view', fn (Entry $entry) => $entry, 'kami.entries.article.view'],
    'create' => ['create', fn () => [Entry::class, 'article'], 'kami.entries.article.create'],
    'update' => ['update', fn (Entry $entry) => $entry, 'kami.entries.article.update'],
    'publish' => ['publish', fn (Entry $entry) => $entry, 'kami.entries.article.publish'],
    'publish on create' => ['publish', fn () => [Entry::class, 'article'], 'kami.entries.article.publish'],
]);

it('takes publish to update a Published or Scheduled Translation, and update alone for a Draft', function () {
    declareRole('author', 'kami.entries.article.update');
    declareRole('publisher', 'kami.entries.article.update', 'kami.entries.article.publish');
    $published = frenchArticle('published');
    $draft = frenchArticle('draft');
    $scheduled = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Hi', 'status' => 'published', 'published_at' => now()->addDay()], Entry::withUnpublished()->find($draft->id), 'en');

    $author = Gate::forUser(userWithRoles('author'));

    expect($author->allows('update', Entry::withUnpublished()->inLocale('fr')->find($published->id)))->toBeFalse()
        ->and(Gate::forUser(userWithRoles('publisher'))->allows('update', Entry::withUnpublished()->inLocale('fr')->find($published->id)))->toBeTrue()
        ->and($author->allows('update', Entry::withUnpublished()->inLocale('fr')->find($draft->id)))->toBeTrue()
        ->and($author->allows('update', Entry::withUnpublished()->inLocale('en')->find($scheduled->id)))->toBeFalse()
        ->and(Gate::forUser(userWithRoles('publisher'))->allows('update', Entry::withUnpublished()->inLocale('en')->find($scheduled->id)))->toBeTrue();
});

it('warns on Role assignments naming an undeclared Role', function () {
    withConversionsServed();
    userWithRoles('administrator', 'ghost');

    $this->artisan('kami:doctor')
        ->expectsOutputToContain('1 Role assignment names a Role config/kami.php does not declare: ghost.')
        ->expectsOutputToContain('docs/guide/users-and-roles.md#roles')
        ->assertSuccessful();
});

it('informs, without failing even with --strict, of Users with no Role who can sign in to a Kami panel', function () {
    withConversionsServed();
    // Filament opens its panel to a User that is not a `FilamentUser` in the local environment only.
    config(['app.env' => 'local']);
    userWithRoles('administrator');
    userWithRoles();

    $this->artisan('kami:doctor', ['--strict' => true])
        ->expectsOutputToContain('1 User with no Kami Role can sign in to the panel admin and sees nothing of Kami.')
        ->expectsOutputToContain('docs/guide/users-and-roles.md#panel-access')
        ->assertSuccessful();
});

it('says nothing of Users the panel turns away', function () {
    withConversionsServed();
    userWithRoles();

    expect((new Doctor)->problems())->toBeEmpty();
});

/**
 * The Audit events about Users and their Roles, as event, subject id, acting User id and context.
 *
 * @return list<array{string, string|null, string|null, array<string, mixed>}>
 */
function userEvents(string ...$except): array
{
    return array_values(array_map(
        fn (array $event): array => [$event['event'], $event['subject_id'], $event['user_id'] === null ? null : (string) $event['user_id'], $event['context']],
        array_filter(auditEvents(), fn (array $event): bool => preg_match('/^kami\.(roles|users)\./', (string) $event['event']) === 1 && ! in_array($event['event'], $except, true)),
    ));
}

describe('AssignRoles', function (): void {
    it('gives the User exactly the Roles listed, recording each one granted or revoked', function (): void {
        declareRole('translator', 'kami.entries.*.view');
        $admin = userWithRoles('administrator');
        $user = userWithRoles('editor');
        $user->kamiRoles();

        Event::fake(RolesAssigned::class);

        resolve(AssignRoles::class)->handle($user, ['translator', 'administrator', 'translator'], $admin);

        Event::assertDispatchedTimes(RolesAssigned::class);
        Event::assertDispatched(fn (RolesAssigned $event): bool => $event->user->is($user) && $event->granted === ['translator', 'administrator'] && $event->revoked === ['editor'] && $event->actor === $admin);
        expect($user->kamiRoles())->toEqualCanonicalizing(['translator', 'administrator'])
            ->and(userEvents('kami.users.created'))->toEqualCanonicalizing([
                ['kami.roles.granted', (string) $user->id, (string) $admin->id, ['role' => 'translator']],
                ['kami.roles.granted', (string) $user->id, (string) $admin->id, ['role' => 'administrator']],
                ['kami.roles.revoked', (string) $user->id, (string) $admin->id, ['role' => 'editor']],
            ]);
    });

    it('records nothing when the User already holds exactly these Roles', function (): void {
        $user = userWithRoles('editor');

        Event::fake(RolesAssigned::class);

        resolve(AssignRoles::class)->handle($user, ['editor']);

        Event::assertNotDispatched(RolesAssigned::class);
        expect(userEvents('kami.users.created'))->toBe([]);
    });

    it('refuses an undeclared Role, changing nothing', function (): void {
        $user = userWithRoles('editor');

        expect(fn () => resolve(AssignRoles::class)->handle($user, ['administrator', 'edtior']))
            ->toThrow(ValidationException::class, 'edtior');

        expect($user->fresh()->kamiRoles())->toBe(['editor'])
            ->and(userEvents('kami.users.created'))->toBe([]);
    });

    it('keeps an undeclared Role the User already holds', function (): void {
        $user = userWithRoles('retired');

        resolve(AssignRoles::class)->handle($user, ['retired', 'editor']);

        expect($user->kamiRoles())->toEqualCanonicalizing(['retired', 'editor']);
    });

    it('refuses to take kami.users.manage from its last holder, recording the refusal', function (): void {
        $admin = userWithRoles('administrator');

        expect(fn () => resolve(AssignRoles::class)->handle($admin, ['editor'], $admin))
            ->toThrow(ValidationException::class, 'last User who may manage Users');

        expect($admin->fresh()->kamiRoles())->toBe(['administrator'])
            ->and(userEvents('kami.users.created'))->toBe([['kami.users.lockout-refused', (string) $admin->id, (string) $admin->id, ['operation' => 'revoke']]]);
    });

    it('takes kami.users.manage from a User while another holds it, through any Role', function (): void {
        declareRole('user-manager', 'kami.users.manage');
        $admin = userWithRoles('administrator');
        userWithRoles('user-manager');

        resolve(AssignRoles::class)->handle($admin, ['editor']);

        expect($admin->fresh()->kamiRoles())->toBe(['editor']);
    });

    it('gives a Role without kami.users.manage while nobody holds it yet, as a seeder before kami:install does', function (): void {
        $user = userWithRoles();

        resolve(AssignRoles::class)->handle($user, ['editor']);

        expect($user->fresh()->kamiRoles())->toBe(['editor']);
    });

    it('keeps the refusal recorded when the caller rolls its own transaction back', function (): void {
        $admin = userWithRoles('administrator');

        try {
            DB::transaction(fn () => resolve(AssignRoles::class)->handle($admin, []));
        } catch (ValidationException) {
        }

        expect(userEvents('kami.users.created'))->toHaveCount(1);
    });
});

describe('HasKamiRoles', function (): void {
    it('records a User created and deleted, with the signed-in User', function (): void {
        $admin = userWithRoles('administrator');
        $this->actingAs($admin);

        $user = userWithRoles('editor');
        $id = (string) $user->id;
        $user->delete();

        expect(userEvents())->toBe([
            ['kami.users.created', (string) $admin->id, null, []],
            ['kami.users.created', $id, (string) $admin->id, []],
            ['kami.users.deleted', $id, (string) $admin->id, []],
        ]);
    });

    it('refuses to delete the last User who may manage Users, recording the refusal', function (): void {
        $admin = userWithRoles('administrator');

        expect(fn () => $admin->delete())->toThrow(ValidationException::class, 'cannot be deleted');

        expect(User::query()->whereKey($admin->id)->exists())->toBeTrue()
            ->and(userEvents('kami.users.created'))->toBe([['kami.users.lockout-refused', (string) $admin->id, null, ['operation' => 'delete']]]);
    });
});

describe('kami:user:grant', function (): void {
    it('gives the Role to the User stored under any spelling of the email, keeping their other Roles', function (): void {
        $user = userWithRoles('editor');
        $user->forceFill(['email' => 'Grace@Example.test'])->save();
        declareRole('translator', 'kami.entries.*.view');

        $this->artisan('kami:user:grant', ['email' => 'grace@example.TEST', 'role' => 'translator'])
            ->expectsOutputToContain('Grace@Example.test now holds the translator Role.')
            ->assertSuccessful();

        expect($user->fresh()->kamiRoles())->toEqualCanonicalizing(['editor', 'translator'])
            ->and(userEvents('kami.users.created'))->toBe([['kami.roles.granted', (string) $user->id, null, ['role' => 'translator']]])
            ->and(auditEvents()[array_key_last(auditEvents())]['actor'])->toBe('cli');
    });

    it('changes nothing for a Role the User already holds', function (): void {
        $user = userWithRoles('editor');

        $this->artisan('kami:user:grant', ['email' => $user->email, 'role' => 'editor'])
            ->expectsOutputToContain('already holds the editor Role')
            ->assertSuccessful();

        expect(userEvents('kami.users.created'))->toBe([]);
    });

    it('refuses, changing nothing, an email no User or several Users have, and an undeclared Role', function (string $email, string $role, string $message): void {
        foreach (['grace@example.test', 'GRACE@example.test'] as $stored) {
            userWithRoles()->forceFill(['email' => $stored])->save();
        }
        userWithRoles()->forceFill(['email' => 'ada@example.test'])->save();

        $this->artisan('kami:user:grant', ['email' => $email, 'role' => $role])
            ->expectsOutputToContain($message)
            ->assertFailed();

        expect(DB::table('kami_role_user')->count())->toBe(0)
            ->and(User::query()->count())->toBe(3);
    })->with([
        'unknown email' => ['nobody@example.test', 'editor', 'No User has the email nobody@example.test.'],
        'not an email' => ['nobody', 'editor', 'must be a valid email address'],
        'several spellings' => ['grace@example.test', 'editor', 'Several Users match this email: grace@example.test, GRACE@example.test.'],
        'undeclared Role' => ['ada@example.test', 'edtior', 'No Role is declared as edtior'],
    ]);
});
