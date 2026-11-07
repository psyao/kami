<?php

declare(strict_types=1);

namespace Psyao\Kami\Users\Internal;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Psyao\Kami\Users\Actions\AssignRoles;

/**
 * The shell's rescue for a site nobody can manage any more (ADR 0014): gives a Role to an existing User, never creating one, and takes no password.
 *
 * @internal
 */
#[Description('Give a Role to an existing User, found by email')]
#[Signature('kami:user:grant
    {email : The User\'s email, in any letter case}
    {role : The handle of a Role declared in config/kami.php}')]
final class GrantRoleCommand extends Command
{
    public function handle(AssignRoles $assign): int
    {
        $email = Arr::string($this->arguments(), 'email');
        $role = Arr::string($this->arguments(), 'role');

        if (($check = Validator::make(['email' => $email], ['email' => UsersByEmail::RULES]))->fails()) {
            $this->components->error($check->errors()->first());

            return self::FAILURE;
        }

        $matches = UsersByEmail::find($email);

        // A Role may hand out every Permission, so it never rests on a guess between two accounts (ADR 0001, step 7).
        if ($matches->count() > 1) {
            $this->components->error('Several Users match this email: ' . UsersByEmail::listed($matches) . '. Fix the duplicates in your users table, then run kami:user:grant again.');

            return self::FAILURE;
        }

        if (($user = $matches->first()) === null) {
            $this->components->error("No User has the email {$email}. kami:user:grant never creates one: create the User first.");

            return self::FAILURE;
        }

        /** @var string $stored */
        $stored = $user->getAttribute('email');

        if (in_array($role, $user->kamiRoles(), true)) {
            $this->components->info("{$stored} already holds the {$role} Role.");

            return self::SUCCESS;
        }

        try {
            $assign->handle($user, [...$user->kamiRoles(), $role]);
        } catch (ValidationException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("{$stored} now holds the {$role} Role.");

        return self::SUCCESS;
    }
}
