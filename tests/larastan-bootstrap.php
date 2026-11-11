<?php

declare(strict_types=1);

use Psyao\Kami\KamiServiceProvider;

// Larastan boots Testbench's skeleton, whose package manifest lists Kami only when Testbench's CLI wrote it with the skeleton's `vendor` symlink
// already there: the CLI deletes a symlink it created, and Larastan, creating it again, rebuilds the manifest without Kami (#543). Registered
// here, Kami's provider and its `kami::` views are known on every machine; when discovery already registered it, this does nothing.
app()->register(KamiServiceProvider::class);
