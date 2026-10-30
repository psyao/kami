<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kami_type_article', function (Blueprint $table): void {
            $table->foreignUlid('entry_id')->constrained('kami_entries')->cascadeOnDelete();
            $table->string('locale', 20);
            $table->string('summary')->nullable();
            $table->string('author')->nullable();
            $table->boolean('featured')->nullable();
            $table->string('accent')->nullable();
            $table->string('website')->nullable();
            $table->string('contact')->nullable();
            $table->integer('pages')->nullable();
            $table->decimal('rating')->nullable();
            $table->date('starts_on')->nullable();
            $table->dateTime('starts_at')->nullable();
            $table->string('kind')->nullable();
            $table->unique(['entry_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kami_type_article');
    }
};
