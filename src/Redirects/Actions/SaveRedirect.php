<?php

declare(strict_types=1);

namespace Psyao\Kami\Redirects\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Psyao\Kami\Audit\Actions\RecordAuditEvent;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Internal\EntryTitles;
use Psyao\Kami\Redirects\Events\RedirectSaved;
use Psyao\Kami\Redirects\Redirect;
use Psyao\Kami\Routing\Internal\Path;
use Psyao\Kami\Sites\Internal\Site;

/**
 * Creates or changes the Redirect at a source of the current Site: 301 or 302 to an Entry or an address, or 410 (ADR 0008). Saving a source already
 * redirected changes that Redirect, so an import replays. It does not authorise: the caller checks the actor's Permission first (ADR 0011).
 *
 * Refusals throw Laravel's `ValidationException`, changing nothing: on `source` for an address no request reaches, one Kami, an Archive, a Feed or
 * another Locale answers, or an Entry's current Path; on `status`; on `target` for a 301 or 302 with none, a 410 with one, an Entry with no address,
 * an address not allow-listed, or one that is itself a Redirect source here, its own source included: Redirects never chain.
 */
final class SaveRedirect
{
    /**
     * @param  string  $source  A path, without its Locale segment, with or without a query string: `/blog`, `/?p=123`, `/?attachment_id` for any value.
     * @param  int  $status  301, 302 or 410.
     * @param  Entry|string|null  $target  An Entry, sent to its current URL; an address (`https://…`, `http://…`, `/…`), sent as stored; null for a 410.
     * @param  string|null  $locale  A declared Locale; null for the default one.
     * @param  Authenticatable|null  $actor  Null for imports and the CLI.
     *
     * @throws InvalidArgumentException For an undeclared Locale.
     * @throws ValidationException
     */
    public function handle(string $source, int $status, Entry | string | null $target = null, ?string $locale = null, ?Authenticatable $actor = null): Redirect
    {
        $site = Site::current();
        $locale ??= $site->defaultLocale();

        if (! in_array($locale, $site->locales, true)) {
            throw new InvalidArgumentException("No Locale [{$locale}] is declared in Site [{$site->handle}].");
        }

        Validator::make(['status' => $status, 'target' => $target instanceof Entry ? null : $target], [
            'status' => ['in:301,302,410'],
            'target' => ['nullable', 'string', 'max:2048'],
        ])->validate();

        [$path, $source] = Path::source($source);

        if (! Path::isAnswerable($path)) {
            throw ValidationException::withMessages(['source' => __('validation.regex', ['attribute' => 'source'])]);
        }

        if (($refusal = Path::refusal($path, $site, $locale)) !== null) {
            throw ValidationException::withMessages(['source' => $refusal]);
        }

        if (mb_strlen($source) > Path::MAX_LENGTH) {
            throw ValidationException::withMessages(['source' => __('validation.max.string', ['attribute' => 'source', 'max' => Path::MAX_LENGTH])]);
        }

        [$entryId, $address] = $this->target($status, $target, $source, $site, $locale);

        [$redirect, $created] = DB::transaction(function () use ($site, $locale, $source, $status, $entryId, $address, $actor): array {
            $redirect = Redirect::query()->withoutGlobalScopes()->lockForUpdate()->firstOrNew(['site' => $site->handle, 'locale' => $locale, 'path' => $source]);

            if ($redirect->exists && $redirect->status === 200) {
                $entry = Entry::query()->withUnpublished()->find($redirect->entry_id);
                $title = $entry instanceof Entry ? EntryTitles::of($entry) : $redirect->entry_id;

                throw ValidationException::withMessages(['source' => __('kami::admin.redirects.live', ['title' => $title])]);
            }

            $created = ! $redirect->exists;
            $redirect->fill(['status' => $status, 'entry_id' => $entryId, 'target' => $address])->save();
            resolve(RecordAuditEvent::class)->handle($created ? 'kami.redirects.created' : 'kami.redirects.updated', $redirect, ['locale' => $locale, 'source' => mb_substr($source, 0, 255), 'status' => $status], $actor);

            return [$redirect, $created];
        });

        event(new RedirectSaved($redirect, $created, $actor));

        return $redirect;
    }

    /**
     * @return array{string|null, string|null} The Entry id and the address, at most one set.
     *
     * @throws ValidationException
     */
    private function target(int $status, Entry | string | null $target, string $source, Site $site, string $locale): array
    {
        if ($status === 410) {
            return $target === null || $target === '' ? [null, null] : throw ValidationException::withMessages(['target' => __('kami::admin.redirects.gone')]);
        }

        if ($target === null || $target === '') {
            throw ValidationException::withMessages(['target' => __('kami::admin.redirects.target')]);
        }

        if ($target instanceof Entry) {
            // A Draft may be a target, answering 404 until it is visible; one in the Trash, of another Site or with no address may not.
            $entry = Entry::query()->withUnpublished()->where('site', $site->handle)->find($target->id);

            return $entry instanceof Entry && $entry->contentType()?->isRoutable() === true ? [$entry->id, null] : throw ValidationException::withMessages(['target' => __('kami::admin.redirects.entry')]);
        }

        $target = trim($target);

        if (! preg_match('~^(?:https?://|/)~i', $target) || ! Path::isLinkable($target)) {
            throw ValidationException::withMessages(['target' => __('kami::admin.redirects.address')]);
        }

        if ($this->chains($target, $source, $site, $locale)) {
            throw ValidationException::withMessages(['target' => __('kami::admin.redirects.chain')]);
        }

        return [null, $target];
    }

    /**
     * Whether a relative address resolves to a Redirect in the Locale, or to the source itself, as `RenderEntry` would read it. An absolute URL is not compared.
     */
    private function chains(string $target, string $source, Site $site, string $locale): bool
    {
        if (! str_starts_with($target, '/')) {
            return false;
        }

        [$path, $query] = explode('?', explode('#', $target, 2)[0], 2) + [1 => ''];
        $path = Path::normalise($path);
        // The Site's own path and the Locale's segment, which no stored Path holds: an address outside them is not in this Locale.
        $base = Path::normalise((string) parse_url($site->url($locale, ''), PHP_URL_PATH));

        if ($base !== '') {
            if ($path !== $base && ! str_starts_with($path, "{$base}/")) {
                return false;
            }

            $path = $path === $base ? '' : substr($path, strlen($base) + 1);
        }

        $candidates = Path::candidates($path, $query);

        return in_array($source, $candidates, true)
            || Redirect::query()->where(['site' => $site->handle, 'locale' => $locale])->whereIn('path', $candidates)->exists();
    }
}
