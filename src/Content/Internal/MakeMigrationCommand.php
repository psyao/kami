<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Support\Internal\ChecksPrompting;

use function Laravel\Prompts\select;

/**
 * Writes one migration that brings the `kami_type_<handle>` tables in line with the declarations (ADR 0006). It never runs it.
 *
 * @internal
 */
#[Description('Write a migration bringing the kami_type_* tables in line with the declared Content Types')]
#[Signature('kami:make:migration
    {type? : A Content Type\'s handle or class, every declared type when left out}')]
final class MakeMigrationCommand extends Command
{
    use ChecksPrompting;

    /** The column type names every engine reports for the column types a Field declares. */
    private const array COLUMN_TYPES = ['varchar' => 'string', 'text' => 'text'];

    private const string DROP = '-';

    public function handle(ContentTypes $types, Migrator $migrator): int
    {
        $handle = $this->argument('type');
        $selected = is_string($handle) ? array_filter([$types->find($handle)]) : $types->all();

        if (is_string($handle) && $selected === []) {
            $this->components->error("No Content Type in kami.types is called {$handle}.");

            return self::FAILURE;
        }

        if (($pending = $this->pendingTypeMigrations($migrator)) !== []) {
            $this->components->error('Run php artisan migrate first: these migrations change kami_type_* tables and have not run yet.');
            $this->components->bulletList($pending);

            return self::FAILURE;
        }

        $changes = [];

        foreach ($selected as $type) {
            $change = $this->change($type);

            if ($change === false) {
                return self::FAILURE;
            }

            if ($change !== null) {
                $changes["kami_type_{$type->handle()}"] = $change;
            }
        }

        if ($changes === []) {
            $this->components->info('Nothing to do: the kami_type_* tables match the declarations.');

            return self::SUCCESS;
        }

        $name = count($changes) > 1 ? 'update_kami_type_tables' : (current($changes)['create'] ? 'create_' : 'update_') . key($changes) . '_table';
        $path = database_path('migrations/' . date('Y_m_d_His') . "_{$name}.php");

        // A generator only creates files: a second run in the same second would overwrite the first.
        if (File::exists($path)) {
            $this->components->error("{$path} already exists. Run the command again.");

            return self::FAILURE;
        }

        File::put($path, $this->migration(array_merge(...array_column($changes, 'up')), array_merge(...array_reverse(array_column($changes, 'down')))));
        $this->components->info("Migration [{$path}] created.");

        return self::SUCCESS;
    }

    /**
     * Run twice before migrating, the command would write the same change twice.
     *
     * @return list<string>
     */
    private function pendingTypeMigrations(Migrator $migrator): array
    {
        $files = $migrator->getMigrationFiles([database_path('migrations'), ...$migrator->paths()]);
        $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];

        return array_values(array_filter(
            array_diff_key($files, array_flip($ran)),
            fn (string $file): bool => str_contains(File::get($file), 'kami_type_'),
        ));
    }

    /**
     * The `Schema` calls creating or altering a type's table and undoing them, `null` when it matches, `false` when the command must stop.
     *
     * @return array{create: bool, up: list<string>, down: list<string>}|false|null
     */
    private function change(ContentType $type): array | false | null
    {
        $table = "kami_type_{$type->handle()}";
        $declared = [];

        foreach ($type->fields() as $field) {
            $declared[$field->handle] = $field->column();
        }

        if (! Schema::hasTable($table)) {
            if ($declared === []) {
                return null;
            }

            return [
                'create' => true,
                'up' => [$this->schema('create', $table, [
                    "\$table->foreignUlid('entry_id')->constrained('kami_entries')->cascadeOnDelete();",
                    "\$table->string('locale', 20);",
                    ...array_map($this->column(...), array_keys($declared), $declared),
                    "\$table->unique(['entry_id', 'locale']);",
                ])],
                'down' => ["Schema::dropIfExists('{$table}');"],
            ];
        }

        $existing = [];

        foreach (Schema::getColumns($table) as $column) {
            if (! in_array($column['name'], ['entry_id', 'locale'], true)) {
                $existing[$column['name']] = self::COLUMN_TYPES[$column['type_name']] ?? $column['type_name'];
            }
        }

        $renamed = $this->renames($table, array_values(array_diff(array_keys($existing), array_keys($declared))), array_values(array_diff(array_keys($declared), array_keys($existing))));

        if ($renamed === null) {
            return false;
        }

        $renames = $unrenames = $up = $down = [];

        foreach ($renamed as $from => $to) {
            $renames[] = "\$table->renameColumn('{$from}', '{$to}');";
            $unrenames[] = "\$table->renameColumn('{$to}', '{$from}');";
            $existing[$to] = $existing[$from];
            unset($existing[$from]);
        }

        $retyped = array_filter(array_intersect_key($declared, $existing), fn (string $column, string $field): bool => $existing[$field] !== $column, ARRAY_FILTER_USE_BOTH);
        $dropped = array_diff_key($existing, $declared);

        // down() writes these columns back as they are: Kami knows how only for the types a Field declares.
        $unknown = array_diff(array_intersect_key($existing, $retyped + $dropped), self::COLUMN_TYPES);

        if (($field = array_key_first($unknown)) !== null) {
            $this->components->error("{$table}.{$field} has type {$unknown[$field]}, which kami:make:migration cannot write back in down(). Write this migration by hand.");

            return false;
        }

        foreach ($retyped as $field => $column) {
            $up[] = $this->column($field, $column, '->change()');
            $down[] = $this->column($field, $existing[$field], '->change()');
        }

        foreach (array_diff_key($declared, $existing) as $field => $column) {
            $up[] = $this->column($field, $column);
            $down[] = "\$table->dropColumn('{$field}');";
        }

        foreach ($dropped as $field => $column) {
            $up[] = "\$table->dropColumn('{$field}');";
            $down[] = $this->column($field, $column);
        }

        if ($renames === [] && $up === []) {
            return null;
        }

        // Laravel runs a Blueprint's change() before its renameColumn(): renames get a Blueprint of their own.
        return [
            'create' => false,
            'up' => array_values(array_filter([$this->schema('table', $table, $renames), $this->schema('table', $table, $up)])),
            'down' => array_values(array_filter([$this->schema('table', $table, array_reverse($down)), $this->schema('table', $table, $unrenames)])),
        ];
    }

    /**
     * Which dropped columns are renamed to which added ones: asked in a terminal, refused without one rather than guessed (ADR 0006).
     *
     * @param  list<string>  $dropped
     * @param  list<string>  $added
     * @return array<string, string>|null
     */
    private function renames(string $table, array $dropped, array $added): ?array
    {
        if ($dropped === [] || $added === []) {
            return [];
        }

        if (! $this->canPrompt()) {
            $this->components->error("{$table} loses " . implode(', ', $dropped) . ' and gains ' . implode(', ', $added) . '. Without a terminal, kami:make:migration cannot tell a rename from a drop and an add, which loses the values. Run it in a terminal to choose, for each lost column, between:');
            $this->components->bulletList(['renaming it to one of ' . implode(', ', $added) . ' (renameColumn, values kept)', 'dropping it (dropColumn, values lost)']);

            return null;
        }

        $renamed = [];

        foreach ($dropped as $from) {
            $candidates = array_diff($added, $renamed);

            if ($candidates === []) {
                $this->components->warn("{$table}.{$from} is dropped, with its values: no new column is left to rename it to.");

                continue;
            }

            $to = select(
                "{$table}.{$from} is no longer declared. Is it renamed?",
                [...array_combine($candidates, array_map(fn (string $to): string => "Renamed to {$to}", $candidates)), self::DROP => 'Dropped, with its values'],
            );

            if ($to !== self::DROP) {
                $renamed[$from] = (string) $to;
            }
        }

        return $renamed;
    }

    /** A Field's column, nullable with no default (ADR 0006). */
    private function column(string $field, string $column, string $suffix = ''): string
    {
        return "\$table->{$column}('{$field}')->nullable(){$suffix};";
    }

    /** @param  list<string>  $statements */
    private function schema(string $method, string $table, array $statements): string
    {
        if ($statements === []) {
            return '';
        }

        $body = implode("\n", array_map(fn (string $statement): string => "            {$statement}", $statements));

        return "Schema::{$method}('{$table}', function (Blueprint \$table): void {\n{$body}\n        });";
    }

    /**
     * @param  list<string>  $up
     * @param  list<string>  $down
     */
    private function migration(array $up, array $down): string
    {
        $up = implode("\n\n        ", $up);
        $down = implode("\n\n        ", $down);

        return <<<PHP
            <?php

            declare(strict_types=1);

            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration
            {
                public function up(): void
                {
                    {$up}
                }

                public function down(): void
                {
                    {$down}
                }
            };

            PHP;
    }
}
