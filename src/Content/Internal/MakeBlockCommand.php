<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Psyao\Kami\Content\Blocks\Block;

/**
 * Writes a Block's class into `app/Blocks` and its view into `resources/views/blocks`, and prints what a site adds elsewhere: a generator only creates files.
 *
 * @internal
 */
#[Description('Start a Block: write its class into app/Blocks, its view into resources/views/blocks, and print the lines to add elsewhere')]
#[Signature('kami:make:block {name : The class name, such as Callout}')]
final class MakeBlockCommand extends Command
{
    public function handle(ContentTypes $types): int
    {
        $argument = $this->input->getArgument('name');
        $name = is_string($argument) ? $argument : '';

        if (! MakeTypeCommand::isClassName($name, Block::class)) {
            $this->components->error("{$name} is not a class name a Block can take: one StudlyCase word, no path, no PHP keyword, not Block.");

            return self::FAILURE;
        }

        $handle = Str::snake($name);

        try {
            $types->validateHandle($name, 'the handle', $handle);
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->components->error($invalidArgumentException->getMessage());

            return self::FAILURE;
        }

        // As Laravel's make:view: the first view path.
        $first = config()->array('view.paths', [])[0] ?? null;
        $views = Str::finish(is_string($first) ? $first : resource_path('views'), '/');
        $namespace = $this->laravel->getNamespace() . 'Blocks';
        $files = [app_path("Blocks/{$name}.php") => $this->declaration($namespace, $name, $handle), "{$views}blocks/{$handle}.blade.php" => $this->view($handle)];

        foreach (array_keys($files) as $path) {
            if (File::exists($path)) {
                $this->components->error("{$path} already exists.");

                return self::FAILURE;
            }
        }

        foreach ($files as $path => $contents) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $contents);
            $this->components->info("[{$path}] created.");
        }

        $this->printNextSteps("{$namespace}\\{$name}", $name, $handle);

        return self::SUCCESS;
    }

    private function declaration(string $namespace, string $name, string $handle): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use Psyao\\Kami\\Content\\Blocks\\Block;
            use Psyao\\Kami\\Content\\Fields\\Text;

            final class {$name} extends Block
            {
                public function handle(): string
                {
                    return '{$handle}';
                }

                public function fields(): array
                {
                    return [
                        Text::make('text')->required(),
                    ];
                }

                public function view(): string
                {
                    return 'blocks.{$handle}';
                }

                public function summary(array \$data): ?string
                {
                    return is_string(\$data['text'] ?? null) ? \$data['text'] : null;
                }
            }

            PHP;
    }

    private function view(string $handle): string
    {
        return <<<BLADE
            {{-- The {$handle} Block, given its data by Field handle. Its markup is yours. --}}
            <aside class="{$handle}">
                <p>{{ \$text }}</p>
            </aside>

            BLADE;
    }

    private function printNextSteps(string $class, string $name, string $handle): void
    {
        $label = Str::ucfirst(Str::lower(Str::headline($name)));

        $this->line('Add it to <comment>blocks</comment> in config/kami.php:');
        $this->line("    {$class}::class,");
        $this->line('Offer it on a RichText Field of a type:');
        $this->line("    use {$class};");
        $this->line("    RichText::make('body')->blocks([{$name}::class]),");
        $this->line('Add its labels to lang/<locale>/kami-blocks.php, for each Locale, with a fields.<field>.label per Field:');
        $this->line("    '{$handle}' => ['label' => '{$label}', 'fields' => ['text' => ['label' => 'Text']]],");
    }
}
