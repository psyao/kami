<?php

declare(strict_types=1);

use Psyao\Kami\Tests\Install\InstallTestCase;
use Psyao\Kami\Tests\TestCase;

// One directory per suite: `Install/` boots without the Workbench.
pest()->extend(TestCase::class)->in('Kami');
pest()->extend(InstallTestCase::class)->in('Install');
