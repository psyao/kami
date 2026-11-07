<?php

declare(strict_types=1);

// Asks the reset request page for a link as a browser does, through Livewire, and prints the notification it answers with.
// Run from the site: `php password-reset.php <base URL> <email>`. Every address must print the same line; a failure goes to stderr with status 1.

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\Factory;

require 'vendor/autoload.php';

function fail(string $message): never
{
    fwrite(STDERR, "{$message}\n");
    exit(1);
}

// The CLI container shows no PHP errors: an HTTP failure (a 404 on the page, say) is named on stderr too.
set_exception_handler(fn (Throwable $exception) => fail($exception::class . ': ' . $exception->getMessage()));

[, $base, $email] = $argv;
$http = (new Factory)->withHeaders(['X-Forwarded-Proto' => 'https'])->withOptions(['cookies' => new CookieJar])->throw();
$page = $http->get("{$base}/admin/password-reset/request")->body();

preg_match('/data-csrf="([^"]+)"/', $page, $csrf) || fail('No CSRF token on the page');
preg_match('/data-update-uri="([^"]+)"/', $page, $update) || fail('No Livewire update URI on the page');
preg_match_all('/wire:snapshot="([^"]+)"/', $page, $raw);
$snapshots = array_map(fn (string $snapshot): string => html_entity_decode($snapshot, ENT_QUOTES), $raw[1]);
$component = fn (string $name): string => collect($snapshots)->first(fn (string $json): bool => data_get(json_decode($json, true), 'memo.name') === $name) ?? fail("No {$name} on the page");

// One Livewire call, as the page's script sends it.
$call = fn (string $snapshot, array $updates, string $method, array $params = []): array => $http->withHeaders(['X-Livewire' => '1'])
    ->post($base . parse_url(html_entity_decode($update[1]), PHP_URL_PATH), [
        '_token' => $csrf[1],
        'components' => [['snapshot' => $snapshot, 'updates' => $updates, 'calls' => [['path' => '', 'method' => $method, 'params' => $params]]]],
    ])->json();

$requested = $call($component('Psyao\Kami\Admin\Internal\RequestPasswordReset'), ['data.email' => $email], 'request');
// Filament keeps the notification in the session; the notifications component then pulls the batch the response names.
$pulled = $call($component('Filament\Livewire\Notifications'), [], 'pullNotifications', [[$requested['filamentNotificationBatch'] ?? fail('No notification')]]);

// Livewire dehydrates each notification as [its data, its type]: a visitor reads its title and body.
$shown = collect(json_decode($pulled['components'][0]['snapshot'], true)['data']['notifications'][0])
    ->map(fn (array $notification): string => $notification[0]['title'] . ' / ' . $notification[0]['body'])->implode(' | ');
echo $shown !== '' ? $shown : fail('No notification shown'), "\n";
