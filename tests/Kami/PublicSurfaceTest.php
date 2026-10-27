<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

// The `kami_` tables are read from the migrated schema.
uses(RefreshDatabase::class);

const REFERENCE_PAGES = [
    'api.md' => 'API',
    'commands.md' => 'Commands',
    'components.md' => 'Components',
    'config.md' => 'Configuration',
    'events.md' => 'Events',
    'permissions.md' => 'Permissions',
];

/**
 * Everything a site can call or name (ADR 0010), sorted. A member's name extends its owner's: `Class::method()`, `kami:doctor --strict`.
 *
 * @return list<array{group: string, name: string, signature: string, summary: string}>
 */
function publicSurface(): array
{
    $elements = [];
    $element = function (string $group, string $name, string $signature = '', string $summary = '') use (&$elements): void {
        $elements[] = ['group' => $group, 'name' => $name, 'signature' => $signature, 'summary' => $summary];
    };

    foreach (publicClasses() as $class) {
        $element('class', $class->getName(), classSignature($class), docSummary($class->getDocComment()));

        // A protected member is reachable by a subclass, so it counts on a class that is not final.
        $visible = $class->isFinal() ? ReflectionMethod::IS_PUBLIC : ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED;
        // Inherited members are listed on their own class; a vendor parent's are its own API. PHP's enum methods come with the kind.
        $own = fn (ReflectionClassConstant | ReflectionMethod | ReflectionProperty $member): bool => $member->getDeclaringClass()->getName() === $class->getName()
            && ! isMarkedInternal($member)
            && ! ($member instanceof ReflectionMethod && $member->isInternal());

        foreach (array_filter($class->getMethods($visible), $own) as $method) {
            $parameters = array_map(fn (ReflectionParameter $parameter): string => preg_replace('/^Parameter #\d+ \[ <\w+> (.*) \]$/', '$1', (string) $parameter), $method->getParameters());
            $signature = implode(' ', Reflection::getModifierNames($method->getModifiers())) . ' function ' . ($method->returnsReference() ? '&' : '') . $method->getName() . '(' . implode(', ', $parameters) . ')' . ($method->hasReturnType() ? ': ' . $method->getReturnType() : '');
            $element('class', "{$class->getName()}::{$method->getName()}()", $signature, docSummary($method->getDocComment()));
        }

        // An enum's only properties are PHP's `name` and `value`.
        foreach ($class->isEnum() ? [] : array_filter($class->getProperties($visible), $own) as $property) {
            $element('class', "{$class->getName()}::\${$property->getName()}", preg_replace('/^Property \[ (.*) \]$/', '$1', trim((string) $property)), docSummary($property->getDocComment()));
        }

        foreach (array_filter($class->getReflectionConstants($visible), $own) as $constant) {
            $signature = $constant->isEnumCase()
                ? 'case ' . $constant->getName() . ($constant->getValue() instanceof BackedEnum ? ' = ' . var_export($constant->getValue()->value, true) : '')
                : implode(' ', Reflection::getModifierNames($constant->getModifiers())) . ' const ' . ($constant->hasType() ? $constant->getType() . ' ' : '') . $constant->getName() . ' = ' . exportedValue($constant->getValue());
            $element('class', "{$class->getName()}::{$constant->getName()}", $signature, docSummary($constant->getDocComment()));
        }
    }

    foreach (Arr::where(Artisan::all(), fn (Command $command, string $name): bool => str_starts_with($name, 'kami:')) as $name => $command) {
        $element('command', $name, summary: $command->getDescription());

        foreach ([...$command->getNativeDefinition()->getArguments(), ...$command->getNativeDefinition()->getOptions()] as $input) {
            $synopsis = (new InputDefinition([$input]))->getSynopsis() . (in_array($input->getDefault(), [null, false, []], true) ? '' : ' = ' . exportedValue($input->getDefault()));
            $element('command', $name . ' ' . ($input instanceof InputOption ? '--' : '') . $input->getName(), $synopsis, $input->getDescription());
        }
    }

    // A list's items are values, not keys.
    $keys = collect(Arr::dot(require dirname(__DIR__, 2) . '/config/kami.php'))->keys()->map(fn (string $key): string => 'kami.' . preg_replace('/\.\d+(\..*)?$/', '', $key))->unique();
    $keys->each(fn (string $key) => $element('config', $key));

    collect(Schema::getTables())->pluck('name')->filter(fn (string $table): bool => str_starts_with($table, 'kami_'))->each(fn (string $table) => $element('table', $table));

    usort($elements, fn (array $a, array $b): int => [$a['group'], $a['name']] <=> [$b['group'], $b['name']]);

    return $elements;
}

/** @param ReflectionClass<object> $class */
function classSignature(ReflectionClass $class): string
{
    $kind = match (true) {
        $class->isInterface() => 'interface',
        $class->isTrait() => 'trait',
        $class->isEnum() => 'enum' . ((new ReflectionEnum($class->getName()))->isBacked() ? ': ' . (new ReflectionEnum($class->getName()))->getBackingType() : ''),
        default => 'class',
    };

    // Only the interfaces the class names itself: the others come with its parent or another interface.
    $interfaces = $class->getInterfaceNames();
    $inherited = array_merge(['UnitEnum', 'BackedEnum'], $class->getParentClass() ? $class->getParentClass()->getInterfaceNames() : [], ...array_map(fn (string $interface): array => (new ReflectionClass($interface))->getInterfaceNames(), $interfaces));
    $named = array_diff($interfaces, $inherited);
    sort($named);

    return implode(' ', array_filter([
        implode(' ', Reflection::getModifierNames($class->getModifiers())),
        $kind,
        $class->getParentClass() ? 'extends ' . $class->getParentClass()->getName() : '',
        $named === [] ? '' : ($class->isInterface() ? 'extends ' : 'implements ') . implode(', ', $named),
    ]));
}

/** The first line of a PHPDoc, unless it starts with a tag. */
function docSummary(string | false $docComment): string
{
    $lines = array_map(fn (string $line): string => trim((string) preg_replace(['#^\s*/\*\*#', '#\*/\s*$#', '#^\s*\*#'], '', $line)), explode("\n", (string) $docComment));
    $first = (string) Arr::first($lines, fn (string $line): bool => $line !== '');

    return str_starts_with($first, '@') ? '' : $first;
}

function exportedValue(mixed $value): string
{
    return (string) preg_replace('/\s+/', ' ', var_export($value, true));
}

/**
 * The generated reference, by file under `docs/reference/`.
 *
 * @param  list<array{group: string, name: string, signature: string, summary: string}>  $surface
 * @return array<string, string>
 */
function referencePages(array $surface): array
{
    $page = fn (array $element): ?string => match ($element['group']) {
        'class' => preg_match('/\\\\Events\\\\\w+$/', Str::before($element['name'], '::')) ? 'events.md' : 'api.md',
        'command' => 'commands.md',
        'config' => 'config.md',
        default => null, // Table names are covered by SemVer but have nothing to document.
    };

    $entry = fn (array $element): string => match (true) {
        // A member: a method, property or constant of a class, an argument or option of a command.
        str_contains($element['name'], '::') || str_contains($element['name'], ' ') => "- `{$element['signature']}`" . ($element['summary'] !== '' ? ": {$element['summary']}" : '') . "\n",
        $element['group'] === 'class' => "\n## `{$element['name']}`\n\n`{$element['signature']}`\n\n" . ($element['summary'] !== '' ? "{$element['summary']}\n\n" : ''),
        $element['group'] === 'command' => "\n## `{$element['name']}`\n\n{$element['summary']}\n\n",
        default => "- `{$element['name']}`\n",
    };

    return collect(REFERENCE_PAGES)->map(function (string $title, string $file) use ($surface, $page, $entry): string {
        $body = collect($surface)->filter(fn (array $element): bool => $page($element) === $file)->map($entry)->implode('');

        return "# {$title}\n\nGenerated by `composer reference` from the public surface (ADR 0010). Do not edit.\n"
            . ($body === '' ? "\nNothing yet.\n" : (str_starts_with($body, "\n") ? '' : "\n") . $body);
    })->map(fn (string $markdown): string => preg_replace("/\n{3,}/", "\n\n", rtrim($markdown) . "\n"))->all();
}

it('snapshots the public surface', function () {
    $lines = array_map(fn (array $element): string => rtrim("{$element['group']} {$element['name']} {$element['signature']}"), publicSurface());

    expect(implode("\n", $lines) . "\n")->toMatchSnapshot();
});

// `composer reference` sets KAMI_REFERENCE=write; any other run checks the committed pages are fresh.
it('keeps docs/reference in step with the public surface', function () {
    $directory = dirname(__DIR__, 2) . '/docs/reference';
    $pages = referencePages(publicSurface());

    if (getenv('KAMI_REFERENCE') === 'write') {
        File::deleteDirectory($directory);
        File::ensureDirectoryExists($directory);

        foreach ($pages as $file => $markdown) {
            File::put("{$directory}/{$file}", $markdown);
        }
    }

    expect(collect(File::files($directory))->map->getFilename()->sort()->values()->all())->toBe(array_keys($pages));

    foreach ($pages as $file => $markdown) {
        expect(File::get("{$directory}/{$file}"))->toBe($markdown, "docs/reference/{$file} is stale: run composer reference.");
    }
});

// ponytail: no collector for `x-kami::` components until the first view; this fails the day one is registered.
it('has no x-kami:: component outside the snapshot yet', function () {
    expect(View::getFinder()->getHints())->not->toHaveKey('kami', 'x-kami:: components need their props in publicSurface()')
        ->and(Blade::getClassComponentNamespaces())->not->toHaveKey('kami', 'x-kami:: components need their props in publicSurface()');
});

// ponytail: no collector for `kami.*` Permissions until Roles land (ADR 0014), which decide where the vocabulary is declared; this fails the day one is defined.
it('has no kami.* Permission outside the snapshot yet', function () {
    expect(collect(Gate::abilities())->keys()->filter(fn (string $ability): bool => str_starts_with($ability, 'kami.'))->all())
        ->toBe([], 'kami.* Permissions need a collector in publicSurface()');
});
