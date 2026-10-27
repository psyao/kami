<?php

use Psyao\Kami\Tests\Install\InstallTestCase;
use Psyao\Kami\Tests\TestCase;

// The `Kami` suite's files sit at the root; `Install/` boots without the Workbench.
pest()->extend(TestCase::class)->in('*Test.php');
pest()->extend(InstallTestCase::class)->in('Install');
