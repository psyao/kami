<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kami_role_user', function (Blueprint $table) {
            /** @var class-string<Model> $users */
            $users = config('auth.providers.users.model');
            $user = new $users;

            $table->ulid('id')->primary();
            // Typed like the host's User key; the assignments go with the User (ADR 0014).
            $table->foreignIdFor($user, 'user_id')->constrained($user->getTable(), $user->getKeyName())->cascadeOnDelete();
            // A declared handle, with no table behind it (ADR 0004).
            $table->string('role', 40);
            $table->unique(['user_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kami_role_user');
    }
};
