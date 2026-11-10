<?php

declare(strict_types=1);

namespace Psyao\Kami\Support\Internal\Install;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Psyao\Kami\Forms\Submission;

/**
 * What `KamiPlugin` reports about the host panels it sits on: the core never imports Filament, the plugin hands it booleans and callbacks (ADR 0011).
 *
 * @internal
 */
final class PluginRegistration
{
    /**
     * Keyed by panel object, one per panel carrying `KamiPlugin`, asked when the doctor runs, since the host may configure the panel after `->plugin()`.
     * `remoteAvatars` when it still draws avatars with Filament's `UiAvatarsProvider`; `admits` answers as Filament does when a User signs in;
     * `middleware` lists the panel's own, by class or alias; `replacedLoginPage` and `replacedResetPage` name the host's own sign-in and reset request pages, which keep Kami's from serving;
     * `setPasswordLink` makes a one-time link to set a User's password on the panel's reset page, `null` when the panel offers no password reset;
     * `requiresKamiMfa` while the panel requires Kami's authenticator-app provider; `storesMfa` when a User model implements Filament's two app-authentication contracts; `hasMfa` once a User has set MFA up.
     *
     * @var array<int, Closure(): array{id: string, guard: string, strict: bool, path: string, domains: list<string>, remoteAvatars: bool, admits: Closure(Authenticatable): bool, middleware: list<string>, replacedLoginPage: ?string, replacedResetPage: ?string, setPasswordLink: (Closure(CanResetPassword): array{url: string, minutes: int})|null, requiresKamiMfa: bool, storesMfa: Closure(string): bool, hasMfa: Closure(Authenticatable): bool}>
     */
    public array $panels = [];

    /**
     * Keyed as `$panels`, each panel's auth guard, asked when an auth event fires: cheaper than the whole report.
     *
     * @var array<int, Closure(): string>
     */
    public array $guards = [];

    /**
     * The panel's name for the User of a key, `null` for a User deleted since: what `SaveEntry`'s refusals name. Unset with no panel.
     *
     * @var (Closure(int|string): ?string)|null
     */
    public ?Closure $userName = null;

    /**
     * The absolute URL of a Submission's page in the admin, for the mail that announces it: the first panel carrying the plugin's. Unset with no panel, or no Form declared.
     *
     * @var (Closure(Submission): string)|null
     */
    public ?Closure $submissionUrl = null;
}
