<?php

declare(strict_types=1);

namespace Psyao\Kami\Scheduling\Internal;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Psyao\Kami\Sites\Internal\Site;

/**
 * Generates the token of the web Tick's URL, for a host's planner, and writes no file: the site owner sets it in `.env` (ADR 0001, ADR 0020).
 *
 * @internal
 */
#[Description('Generate a token for the URL a host\'s planner calls to run the Tick')]
#[Signature('kami:tick:url')]
final class TickUrlCommand extends Command
{
    public function handle(): int
    {
        $token = Str::random(40);

        $url = rtrim(Site::current()->url, '/') . route('kami.tick', $token, absolute: false);
        $this->line("Add this line to .env, replacing any KAMI_TICK_TOKEN line:\n\nKAMI_TICK_TOKEN={$token}\n\nThen have the host's planner call this URL, every 15 minutes or as often as it allows:\n\n{$url}");

        return self::SUCCESS;
    }
}
