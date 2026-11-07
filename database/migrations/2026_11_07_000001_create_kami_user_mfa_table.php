<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A User's authenticator, through `HasKamiMfa`: one row while MFA is set up, none otherwise (ADR 0014).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kami_user_mfa', function (Blueprint $table): void {
            /** @var class-string<Model> $users */
            $users = config('auth.providers.users.model');
            $user = new $users;

            // Typed like the host's User key; the authenticator goes with the User, as its Roles do.
            $table->foreignIdFor($user, 'user_id')->primary()->constrained($user->getTable(), $user->getKeyName())->cascadeOnDelete();
            // Encrypted with the app key: rotating it without `APP_PREVIOUS_KEYS` leaves them unreadable.
            $table->text('secret');
            // Hashed codes, as a JSON list, then encrypted.
            $table->text('recovery_codes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kami_user_mfa');
    }
};
