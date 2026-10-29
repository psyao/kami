<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Psyao\Kami\Facades\Kami;
use Psyao\Kami\Tests\Install\InstallTestCase;
use Psyao\Kami\Tests\TestCase;
use Symfony\Component\Finder\SplFileInfo;

// One directory per suite: `Install/` boots without the Workbench.
pest()->extend(TestCase::class)->in('Kami', 'Volume');
// The browser loads Filament's scripts and Kami's stylesheet from the skeleton's public directory, as on a Site after `kami:install`.
// For every test of the suite, whichever runs first: the browser's server may keep the first test's application.
pest()->extend(TestCase::class)->beforeEach(function () {
    // Filament opens its panel to a User that is not a `FilamentUser` in the local environment only.
    config(['app.env' => 'local']);
    // The suite's array sessions end with each request: signing in needs one that lasts.
    config(['session.driver' => 'file']);
    Kami::routes();
    $this->artisan('filament:assets');
})->in('Browser');
pest()->extend(InstallTestCase::class)->in('Install');

/** `kami:install` as a deploy runs it, with the first administrator's inputs. */
function install(): PendingCommand
{
    return test()->artisan('kami:install', ['--no-interaction' => true, '--email' => 'ada@example.test', '--name' => 'Ada']);
}

/**
 * The anchors of a Markdown file's headings, as GitHub and the Boost resources link them.
 *
 * @return Collection<int, string>
 */
function headingAnchors(string $file): Collection
{
    return Str::of(File::get(dirname(__DIR__) . "/{$file}"))
        ->matchAll('/^#+ (.+)$/m')
        ->map(fn (string $heading): string => Str::slug($heading));
}

/**
 * Every class, enum, interface and trait under `src/`.
 *
 * @return Collection<int, ReflectionClass<object>>
 */
function kamiClasses(): Collection
{
    return collect(File::allFiles(dirname(__DIR__) . '/src'))
        ->map(fn (SplFileInfo $file): ReflectionClass => new ReflectionClass('Psyao\\Kami\\' . str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname())));
}

/** @param ReflectionClass<object> $class */
function isInternal(ReflectionClass $class): bool
{
    return str_contains($class->getNamespaceName() . '\\', '\\Internal\\');
}

function isMarkedInternal(ReflectionClass | ReflectionClassConstant | ReflectionMethod | ReflectionProperty $element): bool
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
