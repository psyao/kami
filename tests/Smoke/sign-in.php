<?php

declare(strict_types=1);

// Signs in through the sign-in page as a browser does, through Livewire, and prints what a visitor reads: the form's error or a notification. A sign-in that passes prints nothing and fails.
// Run from the site: `php sign-in.php <base URL> <email> <password>`; a failure goes to stderr with status 1.

require __DIR__ . '/livewire.php';

[, $base, $email, $password] = $argv;
$page = new LivewirePage($base, '/admin/login');
$response = $page->call('Psyao\Kami\Admin\Internal\Login', ['data.email' => $email, 'data.password' => $password], 'authenticate');
$component = $response['components'][0] ?? fail('No component in the response');

echo match (true) {
    isset($response['filamentNotificationBatch']) => $page->notifications($response),
    default => json_decode($component['snapshot'], true)['memo']['errors']['data.email'][0] ?? fail('Nothing shown: ' . json_encode($response)),
}, "\n";
