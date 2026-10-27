<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Finder\SplFileInfo;

// The closed list of open classes (ADR 0029): a class outside `Internal` is final or abstract unless it is named here.
// Adding to this list opens a class to sites for good, so it is a reviewed change to the public API.
const OPEN_CLASSES = [];

/**
 * Every class, enum, interface and trait under `src/`.
 *
 * @return Collection<int, ReflectionClass<object>>
 */
function kamiClasses(): Collection
{
    return collect(File::allFiles(dirname(__DIR__, 2) . '/src'))
        ->map(fn (SplFileInfo $file): ReflectionClass => new ReflectionClass('Psyao\\Kami\\' . str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname())));
}

/** @param ReflectionClass<object> $class */
function isInternal(ReflectionClass $class): bool
{
    return str_contains($class->getNamespaceName() . '\\', '\\Internal\\');
}

function isMarkedInternal(ReflectionClass | ReflectionMethod | ReflectionProperty $element): bool
{
    return (bool) preg_match('/@internal\b/', (string) $element->getDocComment());
}

/**
 * The classes a site may call or name (ADR 0010).
 *
 * @return Collection<int, ReflectionClass<object>>
 */
function publicClasses(): Collection
{
    return kamiClasses()->reject(isInternal(...))->values();
}

/**
 * The class names a type declaration mentions, unions and intersections included.
 *
 * @return list<string>
 */
function typeNames(?ReflectionType $type): array
{
    return match (true) {
        $type instanceof ReflectionNamedType => [$type->getName()],
        $type instanceof ReflectionUnionType, $type instanceof ReflectionIntersectionType => array_merge(...array_map(typeNames(...), $type->getTypes())),
        default => [],
    };
}

/**
 * The internal types a docblock's type tags name, written in full or through an alias the file imports.
 *
 * @param  array<string, string>  $imports  Internal classes the file imports, by alias.
 * @return list<string>
 */
function docTypeNames(string | false $docComment, array $imports): array
{
    preg_match_all('/(?<!\S)@(?:param|return|var|throws|extends|implements|mixin|method|property\S*|phpstan-\S+|psalm-\S+)\b[^\n]*/', (string) $docComment, $tags);
    $names = [];

    foreach ($tags[0] as $tag) {
        preg_match_all('/[\w\\\\]*\\\\Internal\\\\[\w\\\\]+/', $tag, $written);
        $names = [...$names, ...$written[0]];

        foreach ($imports as $alias => $name) {
            if (preg_match('/(?<![\w\\\\])' . $alias . '\b/', $tag)) {
                $names[] = $name;
            }
        }
    }

    return $names;
}

arch('Filament stays in Admin (ADR 0011)')
    ->expect('Psyao\Kami')
    ->not->toUse('Filament')
    ->ignoring('Psyao\Kami\Admin');

// `ignoring()` skips dependencies as well as targets, so the rule above cannot also forbid Admin.
// The Workbench stands for the host application, whose panel provider adds Kami's plugin.
arch('only Admin uses Admin (ADR 0011)')
    ->expect('Psyao\Kami\Admin')
    ->toOnlyBeUsedIn(['Psyao\Kami\Admin', 'Workbench\App']);

it('marks every class under an Internal namespace @internal (ADR 0010)', function () {
    $unmarked = kamiClasses()
        ->filter(isInternal(...))
        ->reject(isMarkedInternal(...))
        ->map->getName();

    expect($unmarked->all())->toBe([]);
});

it('names no internal type in a public signature (ADR 0010)', function () {
    $leaks = [];

    foreach (publicClasses() as $class) {
        // Larastan reads PHPDoc types as the signature, so a site's code inherits them too.
        preg_match_all('/^use ([\w\\\\]+\\\\Internal\\\\([\w\\\\]+?))(?: as (\w+))?;/m', (string) file_get_contents((string) $class->getFileName()), $uses, PREG_SET_ORDER);
        $imports = collect($uses)->mapWithKeys(fn (array $use): array => [($use[3] ?? '') !== '' ? $use[3] : class_basename($use[1]) => $use[1]])->all();

        $named = [$class->getName() => [
            ...array_filter([$class->getParentClass() ? $class->getParentClass()->getName() : null]),
            ...$class->getInterfaceNames(),
            ...docTypeNames($class->getDocComment(), $imports),
        ]];

        // A protected member is reachable by a subclass, so it counts on an open class.
        $visible = $class->isFinal() ? ReflectionMethod::IS_PUBLIC : ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED;

        // A public method kept from sites is marked on itself (ADR 0010).
        foreach (array_filter($class->getMethods($visible), fn (ReflectionMethod $method): bool => ! isMarkedInternal($method)) as $method) {
            $named["{$class->getName()}::{$method->getName()}()"] = array_merge(
                typeNames($method->getReturnType()),
                docTypeNames($method->getDocComment(), $imports),
                ...array_map(fn (ReflectionParameter $parameter): array => typeNames($parameter->getType()), $method->getParameters()),
            );
        }

        foreach (array_filter($class->getProperties($visible), fn (ReflectionProperty $property): bool => ! isMarkedInternal($property)) as $property) {
            $named["{$class->getName()}::\${$property->getName()}"] = [...typeNames($property->getType()), ...docTypeNames($property->getDocComment(), $imports)];
        }

        foreach ($named as $element => $types) {
            foreach ($types as $type) {
                if (str_contains($type, '\\Internal\\')) {
                    $leaks[] = "{$element} names {$type}";
                }
            }
        }
    }

    expect(array_values(array_unique($leaks)))->toBe([]);
});

it('keeps every class final unless it is abstract or open (ADR 0029)', function () {
    $open = publicClasses()
        ->filter(fn (ReflectionClass $class): bool => ! $class->isInterface() && ! $class->isTrait() && ! $class->isEnum())
        ->reject(fn (ReflectionClass $class): bool => $class->isFinal() || $class->isAbstract() || in_array($class->getName(), OPEN_CLASSES, true))
        ->map->getName();

    expect($open->values()->all())->toBe([]);
});

it('gives every public class a PHPDoc summary, for the generated reference', function () {
    $undocumented = publicClasses()
        ->reject(fn (ReflectionClass $class): bool => (bool) preg_match('/^\/\*\*\s*(\*\s*)?[^@\s*]/', (string) $class->getDocComment()))
        ->map->getName();

    expect($undocumented->values()->all())->toBe([]);
});

it('describes every command', function () {
    $undescribed = kamiClasses()
        ->filter(fn (ReflectionClass $class): bool => $class->isSubclassOf(Command::class) && $class->isInstantiable())
        ->reject(fn (ReflectionClass $class): bool => resolve($class->getName())->getDescription() !== '')
        ->map->getName();

    expect($undescribed->values()->all())->toBe([]);
});
