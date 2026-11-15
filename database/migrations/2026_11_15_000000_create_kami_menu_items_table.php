<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The items of the Menus `config/kami.php` declares, one tree per Site and Menu for all its Locales (ADR 0025).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kami_menu_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('site', 40);
            // The Menu's handle in `config/kami.php`: no table, as the declaration lives in code.
            $table->string('menu', 40);
            $table->ulid('parent_id')->nullable();
            $table->unsignedInteger('position');
            // One target: an Entry, an Archive by its type's handle, or an address in `url`. `RESTRICT`: `DeleteEntry` removes the items first.
            $table->foreignUlid('entry_id')->nullable()->constrained('kami_entries')->restrictOnDelete();
            $table->string('archive', 40)->nullable();
            // Both keyed by Locale code, never read by SQL (ADR 0006).
            $table->json('url')->nullable();
            $table->json('label')->nullable();
            $table->boolean('new_tab')->default(false);
            $table->datetimes();

            $table->index(['site', 'menu', 'parent_id', 'position']);

            // MySQL and MariaDB index every foreign key already; `DeleteEntry` reads the Entry's items.
            if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $table->index('entry_id');
            }
        });

        // Once the table and its primary key exist: PostgreSQL refuses a key to itself in the same statement.
        Schema::table('kami_menu_items', function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('kami_menu_items')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kami_menu_items');
    }
};
