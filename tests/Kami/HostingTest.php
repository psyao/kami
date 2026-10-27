<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Process;

// Infomaniak's shared hosting disables these on the web (tests/infomaniak.ini), and runs no worker: Kami never reaches for them.
arch('Kami avoids insecure functions')->preset()->security();

arch('Kami calls nothing Infomaniak disables or a worker would run')
    ->expect('Psyao\Kami')
    ->not->toUse([
        'proc_open',
        'popen',
        'mail',
        Process::class,
        'Symfony\Component\Process',
        Concurrency::class,
        ShouldQueue::class,
    ]);
