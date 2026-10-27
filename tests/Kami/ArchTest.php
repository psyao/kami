<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
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
        ->reject(fn (ReflectionClass $class): bool => str_contains((string) $class->getDocComment(), '@internal'))
        ->map->getName();

    expect($unmarked->all())->toBe([]);
});

it('names no internal type in a public signature (ADR 0010)', function () {
    $leaks = [];

    foreach (publicClasses() as $class) {
        $named = [$class->getName() => [...array_filter([$class->getParentClass() ? $class->getParentClass()->getName() : null]), ...$class->getInterfaceNames()]];

        // A protected member is reachable by a subclass, so it counts on an open class.
        $visible = $class->isFinal() ? ReflectionMethod::IS_PUBLIC : ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED;

        foreach ($class->getMethods($visible) as $method) {
            // A public method kept from sites is marked on itself (ADR 0010).
            if (! str_contains((string) $method->getDocComment(), '@internal')) {
                $named["{$class->getName()}::{$method->getName()}()"] = array_merge(typeNames($method->getReturnType()), ...array_map(fn (ReflectionParameter $parameter): array => typeNames($parameter->getType()), $method->getParameters()));
            }
        }

        foreach ($class->getProperties($visible) as $property) {
            if (! str_contains((string) $property->getDocComment(), '@internal')) {
                $named["{$class->getName()}::\${$property->getName()}"] = typeNames($property->getType());
            }
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
