<?php

declare(strict_types=1);

namespace Psyao\Kami\Forms\Internal;

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Psyao\Kami\Forms\Form;
use Psyao\Kami\Forms\Submission;
use Psyao\Kami\Kami;
use Throwable;

/**
 * Sends a Submission's notification one channel at a time, recording each attempt on its `Delivery` row. Synchronous on
 * purpose: Kami's side effects never wait on a queue worker. After the response through `defer()`, then from `kami:forms:deliver`.
 *
 * @internal
 */
final class Deliveries
{
    /** The container key of the Admin language, `app.locale` as the service provider found it. */
    public const string ADMIN_LANGUAGE = 'kami.admin-language';

    /**
     * Writes a pending row per channel of the Submission's notification, inside the caller's transaction. `mail` with no recipient is
     * left out, with a warning: the Submission is still stored.
     */
    public static function prepare(Form $form, Submission $submission): void
    {
        $channels = self::channels($form, $submission);

        if (in_array('mail', $channels, true) && $form->recipients() === []) {
            Log::warning("The Form [{$form->handle()}] has no recipient: its Submission [{$submission->id}] is stored but not mailed.");
            $channels = array_diff($channels, ['mail']);
        }

        foreach ($channels as $channel) {
            Delivery::query()->create(['submission_id' => $submission->id, 'channel' => $channel]);
        }
    }

    /**
     * The channels of the Submission's notification, `mail` included even with no recipient.
     *
     * @return list<string>
     */
    public static function channels(Form $form, Submission $submission): array
    {
        $notification = $form->notification($submission);
        // As Laravel's sender calls it: `via()` is a convention, not a method of `Notification`.
        $via = method_exists($notification, 'via') ? $notification->via(self::notifiable($form)) : [];

        return array_values(array_unique(array_filter((array) $via, is_string(...))));
    }

    /** Sends every channel of a Submission not delivered yet: what `defer()` runs after the response. */
    public static function sendPending(Submission $submission): void
    {
        $pending = Delivery::query()->pending()->where('submission_id', $submission->id)->get();

        // Not `each()`: it stops at the first `false`, a failed channel.
        foreach ($pending as $delivery) {
            self::send($delivery->setRelation('submission', $submission));
        }
    }

    /**
     * One attempt through one channel; a failure is recorded and reported, never thrown, so the next channel still goes.
     * Null when another sender claimed the delivery since it was read.
     */
    public static function send(Delivery $delivery): ?bool
    {
        // The claim: only the sender that read the current `attempts` moves it on. Counted first, so an attempt that dies mid-send,
        // out of time or memory, still counts towards abandoning it; `last_error` cleared, so the row reads as being sent (`Delivery::sending()`).
        $claim = ['attempts' => $delivery->attempts + 1, 'last_error' => null, 'updated_at' => now()];

        if (Delivery::query()->whereKey($delivery->getKey())->whereNull('delivered_at')->where('attempts', $delivery->attempts)->update($claim) === 0) {
            return null;
        }

        $delivery->forceFill($claim)->syncOriginal();

        try {
            $submission = $delivery->submission;
            $form = resolve(Kami::class)->form($submission->form);
            $notification = $form->notification($submission);
            // The visitor's request runs in the page's Locale; the site owner reads the Admin language.
            $notification->locale ??= is_string($language = resolve(self::ADMIN_LANGUAGE)) ? $language : null;

            Notification::sendNow(self::notifiable($form), $notification, [$delivery->channel]);
        } catch (Throwable $exception) {
            report($exception);
            $delivery->update(['last_error' => Str::limit($exception::class . ': ' . $exception->getMessage(), 1000)]);

            return false;
        }

        $delivery->update(['delivered_at' => now()]);

        return true;
    }

    private static function notifiable(Form $form): AnonymousNotifiable
    {
        return Notification::route('mail', $form->recipients());
    }
}
