<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Process;

// Pest's own list of unsafe functions, which already holds exec, shell_exec, system and passthru.
arch('Kami avoids insecure functions')->preset()->security();

// The rest of what Infomaniak's shared hosting disables on the web (tests/infomaniak.ini), and a worker it does not run.
arch('Kami calls nothing Infomaniak disables or a worker would run')
    ->expect('Psyao\Kami')
    ->not->toUse([
        'proc_open',
        'popen',
        'mail',
        'pcntl_exec',
        Process::class,
        'Illuminate\Process',
        'Symfony\Component\Process',
        Concurrency::class,
        'Illuminate\Concurrency',
        ShouldQueue::class,
    ]);
