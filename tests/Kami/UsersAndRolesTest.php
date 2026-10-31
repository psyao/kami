<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User as PlainUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Support\Internal\Doctor\Doctor;
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

it('names one Permission per action on each declared type, plus kami.users.manage', function () {
    expect(kamiPermissions())->toEqualCanonicalizing([
        ...collect(['article', 'page', 'label', 'project', 'home', 'testimonial'])->crossJoin(['view', 'create', 'update', 'publish'])->map(fn (array $pair) => "kami.entries.{$pair[0]}.{$pair[1]}"),
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
    $user = userWithRoles('administrator');

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
    userWithRoles('administrator', 'ghost');

    $this->artisan('kami:doctor')
        ->expectsOutputToContain('1 Role assignment names a Role config/kami.php does not declare: ghost.')
        ->expectsOutputToContain('docs/guide/users-and-roles.md#roles')
        ->assertSuccessful();
});

it('informs, without failing even with --strict, of Users with no Role who can sign in to a Kami panel', function () {
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
    userWithRoles();

    expect((new Doctor)->problems())->toBeEmpty();
});
