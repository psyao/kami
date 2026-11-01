<?php

declare(strict_types=1);

use Laravel\Boost\Install\Agents\ClaudeCode;
use Laravel\Boost\Install\Skill;
use Laravel\Boost\Install\SkillWriter;
use Orchestra\Testbench\Foundation\Application;

/**
 * Renders Boost's Laravel skills into `.claude/skills/`: `boost:install` would write them in Testbench's skeleton, and read PHPUnit there.
 *
 * ponytail: Boost's internal classes, so its next major may break this; `composer link-skills` then ends quietly, and `boost:install` run from the root replaces it if Boost learns packages.
 */
require __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);
$app = Application::create(basePath: "{$root}/vendor/orchestra/testbench-core/laravel");
$app->setBasePath($root);

$writer = new SkillWriter($app->make(ClaudeCode::class));

foreach (['laravel-best-practices', 'testing-best-practices'] as $name) {
    $writer->write(new Skill($name, 'laravel/boost', "{$root}/vendor/laravel/boost/.ai/laravel/skill/{$name}", ''));
}
