<?php

declare(strict_types=1);

namespace Psyao\Kami\Routing\Internal;

use Illuminate\Http\Request;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Internal\EntrySnapshot;
use Psyao\Kami\Content\Internal\PreviewLink;
use Psyao\Kami\Content\Internal\Translation;
use Psyao\Kami\Sites\Internal\Site;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/kami/share/{token}`: a Translation's saved state, Draft or Scheduled, for anyone holding an unexpired link (ADR 0019).
 * Unknown, expired, revoked or trashed answers the same 404.
 *
 * @internal
 */
final class RenderPreviewLink
{
    public function __invoke(Request $request, string $token): Response
    {
        $site = Site::current();
        $link = PreviewLink::unexpired()->where('token_hash', hash('sha256', $token))->first() ?? abort(404);
        abort_unless(in_array($link->locale, $site->locales, true), 404);

        // `withUnpublished()` keeps the Trash out, so a restored Entry's link works again.
        $entry = Entry::query()->withUnpublished()->inLocale($link->locale)->whereKey($link->entry_id)->first();
        $type = $entry?->contentType();
        $translation = $entry?->translations->whereIn('locale', [$link->locale, '*'])->first();
        abort_unless($entry !== null && $translation instanceof Translation && $type?->isRoutable() === true, 404);

        // Through a snapshot of what is stored, so the scope is lifted for this Translation only: what it links to reads as visitors see it.
        $entry = EntrySnapshot::rebuild(EntrySnapshot::capture($type, $entry, ['locale' => $translation->locale, 'data' => $translation->only(Translation::ATTRIBUTES)], $link->locale));

        // The expiry in the Site's time zone and the link's Locale, which the page renders in.
        $expiresAt = $link->expires_at->setTimezone($site->timezone)->settings(['locale' => $link->locale])->isoFormat('LLL');

        return PreviewResponse::make($request, $type, $entry, __('kami::admin.preview_links.banner', ['date' => $expiresAt], $link->locale));
    }
}
