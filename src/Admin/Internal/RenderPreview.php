<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal;

use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Psyao\Kami\Content\Internal\EntrySnapshot;
use Psyao\Kami\Routing\Internal\PreviewResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The page an editor's unsaved form makes, through its type's `render()`, for that User only (ADR 0019): another one gets a 404.
 *
 * @internal
 *
 * @phpstan-import-type Snapshot from EntrySnapshot
 */
final class RenderPreview
{
    private const string CACHE_KEY = 'kami.preview.';

    /**
     * Holds a snapshot for the current User, one hour, under the page's token, never in the session; a later Preview of the page replaces it.
     *
     * @param  Snapshot  $snapshot
     */
    public static function store(string $token, array $snapshot): void
    {
        Cache::put(self::CACHE_KEY . $token, ['user' => Filament::auth()->id(), 'snapshot' => $snapshot], now()->addHour());
    }

    public function __invoke(Request $request, string $token): Response
    {
        /** @var array{user: int|string, snapshot: Snapshot}|null $preview */
        $preview = Cache::get(self::CACHE_KEY . $token);
        abort_unless(is_array($preview) && $preview['user'] === Filament::auth()->id(), 404);

        $entry = EntrySnapshot::rebuild($preview['snapshot']);
        $type = $entry->contentType();
        abort_unless($type?->isRoutable() === true && Gate::forUser(Filament::auth()->user())->allows("kami.entries.{$type->handle()}.view"), 404);

        return PreviewResponse::make($request, $type, $entry);
    }
}
