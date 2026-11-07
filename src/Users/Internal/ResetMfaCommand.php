<?php

declare(strict_types=1);

namespace Psyao\Kami\Users\Internal;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;

/**
 * The shell's rescue for a User who lost both its authenticator and its recovery codes (ADR 0014): it sets MFA up again at its next sign-in.
 *
 * @internal
 */
#[Description('Clear the authenticator of a User, found by email')]
#[Signature('kami:user:reset-mfa
    {email : The User\'s email, in any letter case}')]
final class ResetMfaCommand extends Command
{
    public function handle(): int
    {
        $email = Arr::string($this->arguments(), 'email');

        if (($check = Validator::make(['email' => $email], ['email' => UsersByEmail::RULES]))->fails()) {
            $this->components->error($check->errors()->first());

            return self::FAILURE;
        }

        $matches = UsersByEmail::find($email);

        // Which account's second factor goes never rests on a guess between two (ADR 0001, step 7).
        if ($matches->count() > 1) {
            $this->components->error('Several Users match this email: ' . UsersByEmail::listed($matches) . '. Fix the duplicates in your users table, then run kami:user:reset-mfa again.');

            return self::FAILURE;
        }

        if (($user = $matches->first()) === null) {
            $this->components->error("No User has the email {$email}.");

            return self::FAILURE;
        }

        /** @var string $stored */
        $stored = $user->getAttribute('email');

        // A model keeping its authenticator on columns of its own is not Kami's to clear.
        $this->components->info(UserMfa::reset($user) ? "{$stored} sets MFA up again at the next sign-in." : "{$stored} has no MFA set up in kami_user_mfa.");

        return self::SUCCESS;
    }
}
