<?php

declare(strict_types=1);

// Asks the reset request page for a link as a browser does, through Livewire, and prints the notification it answers with.
// Run from the site: `php password-reset.php <base URL> <email>`. Every address must print the same line; a failure goes to stderr with status 1.

require __DIR__ . '/livewire.php';

[, $base, $email] = $argv;
$page = new LivewirePage($base, '/admin/password-reset/request');
$shown = $page->notifications($page->call('Psyao\Kami\Admin\Internal\RequestPasswordReset', ['data.email' => $email], 'request'));

echo $shown !== '' ? $shown : fail('No notification shown'), "\n";
