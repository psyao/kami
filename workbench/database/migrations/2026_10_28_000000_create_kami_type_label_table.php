<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ponytail: written by hand until `kami:make:migration` (#101) generates it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kami_type_label', function (Blueprint $table) {
            $table->foreignUlid('entry_id')->constrained('kami_entries')->cascadeOnDelete();
            $table->string('locale', 20);
            $table->string('colour')->nullable();
            $table->unique(['entry_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kami_type_label');
    }
};
