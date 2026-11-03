<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A Translation's saved state shared without an account (ADR 0019): the token's SHA-256 only, never the token.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kami_preview_links', function (Blueprint $table): void {
            /** @var class-string<Model> $users */
            $users = config('auth.providers.users.model');
            $user = new $users;

            $table->ulid('id')->primary();
            $table->foreignUlid('entry_id')->constrained('kami_entries')->cascadeOnDelete();
            $table->string('locale', 20);
            $table->char('token_hash', 64)->unique();
            $table->datetime('expires_at');
            $table->foreignIdFor($user, 'created_by')->nullable()->constrained($user->getTable(), $user->getKeyName())->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kami_preview_links');
    }
};
