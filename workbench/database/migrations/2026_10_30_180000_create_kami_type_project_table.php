<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kami_type_project', function (Blueprint $table): void {
            $table->foreignUlid('entry_id')->constrained('kami_entries')->cascadeOnDelete();
            $table->string('locale', 20);
            $table->string('client')->nullable();
            $table->boolean('featured')->nullable();
            $table->string('featured_note')->nullable();
            $table->string('kind')->nullable();
            $table->string('source')->nullable();
            $table->text('story')->nullable();
            $table->date('launched_on')->nullable();
            $table->json('links')->nullable();
            $table->json('address')->nullable();
            $table->unique(['entry_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kami_type_project');
    }
};
