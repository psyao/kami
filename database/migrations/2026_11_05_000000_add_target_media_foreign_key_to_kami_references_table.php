<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A Media Field's rows (ADR 0005): a Media item an Entry links cannot be deleted under it (RESTRICT); the source's deletion still cascades.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            $this->rebuildOnSqlite();
        } else {
            Schema::table('kami_references', fn (Blueprint $table) => $table->foreign('target_media_id')->references('id')->on('kami_media')->restrictOnDelete());
        }

        Schema::table('kami_references', fn (Blueprint $table) => $table->index('target_media_id'));
    }

    public function down(): void
    {
        // The key first: MySQL refuses to drop the index a foreign key uses.
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('kami_references', fn (Blueprint $table) => $table->dropForeign(['target_media_id']));
        }

        Schema::table('kami_references', fn (Blueprint $table) => $table->dropIndex(['target_media_id']));
    }

    /**
     * SQLite adds a foreign key only by creating the table again, and Laravel's rebuild drops the one-target CHECK: the stored definition is kept, the key appended.
     */
    private function rebuildOnSqlite(): void
    {
        $prefix = Schema::getConnection()->getTablePrefix();
        $table = "{$prefix}kami_references";
        $sql = DB::scalar('select sql from sqlite_master where type = ? and name = ?', ['table', $table]);
        assert(is_string($sql));
        // Raw: the query builder would prefix `sqlite_master`. Indexes SQLite makes itself have no `sql`.
        /** @var list<object{sql: string}> $indexes */
        $indexes = DB::select('select sql from sqlite_master where type = ? and tbl_name = ? and sql is not null', ['index', $table]);
        $temporary = "{$table}__kami_rebuild";
        $definition = substr_replace($sql, ", foreign key(\"target_media_id\") references \"{$prefix}kami_media\"(\"id\") on delete restrict)", (int) strrpos($sql, ')'), 1);

        DB::statement((string) preg_replace('/^create table "?' . preg_quote($table, '/') . '"?/i', "create table \"{$temporary}\"", $definition));
        DB::statement("insert into \"{$temporary}\" select * from \"{$table}\"");
        DB::statement("drop table \"{$table}\"");
        DB::statement("alter table \"{$temporary}\" rename to \"{$table}\"");

        foreach ($indexes as $index) {
            DB::statement($index->sql);
        }
    }
};
