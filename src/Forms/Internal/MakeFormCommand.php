<?php

declare(strict_types=1);

namespace Psyao\Kami\Forms\Internal;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Psyao\Kami\Content\Internal\ContentTypes;

/**
 * Writes a Form's class into `app/Forms` and its accessible view into `resources/views/forms`, and prints what a site adds elsewhere:
 * a generator only creates files.
 *
 * @internal
 */
#[Description('Start a Form: write its class into app/Forms, its view into resources/views/forms, and print the lines to add elsewhere')]
#[Signature('kami:make:form {name : The Form\'s name, such as Contact; a trailing Form is dropped}')]
final class MakeFormCommand extends Command
{
    /** The generated inputs: their ids are their names, so the Form's own id, its handle, must be none of them. */
    private const array FIELDS = ['name', 'email', 'message'];

    public function handle(ContentTypes $types): int
    {
        $argument = $this->input->getArgument('name');
        $given = is_string($argument) ? $argument : '';
        $name = Str::replaceEnd('Form', '', $given);
        $class = "{$name}Form";

        // `{$name}Form` is never a PHP keyword, so StudlyCase is the whole check; `Form` alone leaves no name.
        if (preg_match('/^[A-Z][A-Za-z0-9]*$/', $name) !== 1) {
            $this->components->error("{$given} is not a name a Form can take: one StudlyCase word, no path, not Form alone.");

            return self::FAILURE;
        }

        $handle = Str::snake($name);

        if (in_array($handle, self::FIELDS, true)) {
            $this->components->error("The handle \"{$handle}\" is also the id of one of its fields: choose another name.");

            return self::FAILURE;
        }

        try {
            $types->validateHandle($class, 'the handle', $handle);
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->components->error($invalidArgumentException->getMessage());

            return self::FAILURE;
        }

        // As Laravel's make:view: the first view path.
        $first = config()->array('view.paths', [])[0] ?? null;
        $views = Str::finish(is_string($first) ? $first : resource_path('views'), '/');
        $namespace = $this->laravel->getNamespace() . 'Forms';
        $files = [app_path("Forms/{$class}.php") => $this->declaration($namespace, $class), "{$views}forms/{$handle}.blade.php" => $this->view($handle)];

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

        $this->printNextSteps("{$namespace}\\{$class}", $handle);

        return self::SUCCESS;
    }

    private function declaration(string $namespace, string $class): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use Psyao\\Kami\\Forms\\Form;

            final class {$class} extends Form
            {
                public function rules(): array
                {
                    return [
                        'name' => ['required', 'string', 'max:100'],
                        'email' => ['required', 'email', 'max:255'],
                        'message' => ['required', 'string', 'max:5000'],
                    ];
                }

                public function recipients(): array
                {
                    return [];
                }

                public function replyTo(): string
                {
                    return 'email';
                }
            }

            PHP;
    }

    private function view(string $handle): string
    {
        return strtr(<<<'BLADE'
            {{-- The {handle} Form. Its markup is yours: keep each input's id equal to its name, which the error summary links to. --}}
            @use(Psyao\Kami\Facades\Kami)
            <section aria-labelledby="{handle}-heading">
                <h2 id="{handle}-heading">{{ __('kami-forms.{handle}.label') }}</h2>
                @if (Kami::form('{handle}')->sent())
                    <p id="{handle}" role="status">{{ __('kami-forms.{handle}.sent') }}</p>
                @else
                    <x-kami::form form="{handle}">
                        <x-kami::form.errors level="3" />

                        <p>
                            <label for="name">{{ ucfirst(__('validation.attributes.name')) }}</label>
                            <input type="text" id="name" name="name" value="{{ old('name') }}" required maxlength="100" autocomplete="name"
                                @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                            @error('name')<span id="name-error">{{ $message }}</span>@enderror
                        </p>
                        <p>
                            <label for="email">{{ ucfirst(__('validation.attributes.email')) }}</label>
                            <input type="email" id="email" name="email" value="{{ old('email') }}" required maxlength="255" autocomplete="email"
                                @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                            @error('email')<span id="email-error">{{ $message }}</span>@enderror
                        </p>
                        <p>
                            <label for="message">{{ ucfirst(__('validation.attributes.message')) }}</label>
                            <textarea id="message" name="message" required maxlength="5000"
                                @error('message') aria-invalid="true" aria-describedby="message-error" @enderror>{{ old('message') }}</textarea>
                            @error('message')<span id="message-error">{{ $message }}</span>@enderror
                        </p>
                        <p><button type="submit">{{ __('kami-forms.{handle}.send') }}</button></p>
                    </x-kami::form>
                @endif
            </section>

            BLADE, ['{handle}' => $handle]);
    }

    private function printNextSteps(string $class, string $handle): void
    {
        $label = Str::headline($handle);

        $this->line('Add it to <comment>forms</comment> in config/kami.php:');
        $this->line("    {$class}::class,");
        $this->line('Name who receives its Submissions in recipients(), or they are stored and mailed to nobody.');
        $this->line('Add its strings to lang/<locale>/kami-forms.php, for each Locale:');
        $this->line("    '{$handle}' => ['label' => '{$label}', 'send' => 'Send', 'sent' => 'Thank you, your message is sent.'],");
        $this->line('Add its field labels to <comment>attributes</comment> in lang/<locale>/validation.php, for each Locale:');
        $this->line("    'name' => 'name', 'email' => 'email address', 'message' => 'message',");
        $this->line('A Locale other than English also needs Laravel\'s validation messages in that file, from laravel-lang:');
        $this->line('    composer require laravel-lang/common --dev && php artisan lang:add <locale>');
        $this->line('Include its view in the view of the page that holds it:');
        $this->line("    @include('forms.{$handle}')");
    }
}
