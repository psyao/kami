<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpToken;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\GlobalSet;
use Psyao\Kami\Content\Singleton;
use Psyao\Kami\Content\Taxonomy;

/**
 * Writes a Content Type's class into `app/Content` and prints what a site adds elsewhere: a generator only creates files.
 *
 * @internal
 */
#[Description('Start a Content Type: write its class into app/Content and print the lines to add elsewhere')]
#[Signature('kami:make:type
    {name : The class name, such as NewsItem}
    {--singleton : One Entry per Site, such as a home page}
    {--global-set : One Entry with no URL, holding values shown across the Site}
    {--taxonomy : Terms classifying other types}')]
final class MakeTypeCommand extends Command
{
    public function handle(ContentTypes $types): int
    {
        $bases = array_filter([
            ContentType::class => ! $this->option('singleton') && ! $this->option('global-set') && ! $this->option('taxonomy'),
            Singleton::class => $this->option('singleton'),
            GlobalSet::class => $this->option('global-set'),
            Taxonomy::class => $this->option('taxonomy'),
        ]);

        if (count($bases) > 1) {
            $this->components->error('--singleton, --global-set and --taxonomy exclude one another: pass one at most.');

            return self::FAILURE;
        }

        $base = (string) array_key_first($bases);
        $name = (string) $this->argument('name');

        if (! $this->isClassName($name, $base)) {
            $this->components->error("{$name} is not a class name a type can take: one StudlyCase word, no path, no PHP keyword, not the name of the class it extends.");

            return self::FAILURE;
        }

        $handle = Str::snake($name);

        try {
            $types->validateHandle($name, 'the handle', $handle);
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->components->error($invalidArgumentException->getMessage());

            return self::FAILURE;
        }

        $path = app_path("Content/{$name}.php");

        if (File::exists($path)) {
            $this->components->error("{$path} already exists.");

            return self::FAILURE;
        }

        $namespace = $this->laravel->getNamespace() . 'Content';

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $this->declaration($namespace, $name, $base));
        $this->components->info("Content Type [{$path}] created.");
        $this->printNextSteps("{$namespace}\\{$name}", $name, $handle, $base);

        return self::SUCCESS;
    }

    private function isClassName(string $name, string $base): bool
    {
        return preg_match('/^[A-Z][A-Za-z0-9]*$/', $name) === 1
            && PhpToken::tokenize("<?php {$name}")[1]->is(T_STRING)
            && $name !== class_basename($base);
    }

    private function declaration(string $namespace, string $name, string $base): string
    {
        $parent = class_basename($base);

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use {$base};

            final class {$name} extends {$parent}
            {
                public function fields(): array
                {
                    return [];
                }
            }

            PHP;
    }

    private function printNextSteps(string $class, string $name, string $handle, string $base): void
    {
        $label = Str::headline($name);
        $labels = is_a($base, Singleton::class, true) ? "['label' => '{$label}']" : "['label' => '{$label}', 'plural' => '" . Str::plural($label) . "']";

        $this->line('Add it to <comment>types</comment> in config/kami.php:');
        $this->line("    {$class}::class,");
        $this->line('Declare its Fields, then write its table:');
        $this->line("    php artisan kami:make:migration {$handle}");
        $this->line('Add its labels to lang/<locale>/kami-types.php, for each Locale:');
        $this->line("    '{$handle}' => {$labels},");

        if ($base !== GlobalSet::class) {
            $this->line('Create its view, or override render():');
            $this->line("    resources/views/kami/{$handle}.blade.php");
        }
    }
}
