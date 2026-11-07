<?php

declare(strict_types=1);

namespace Psyao\Kami\Audit\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Psyao\Kami\Audit\AuditContext;
use Psyao\Kami\Audit\Events\AuditEventRecorded;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Internal\PreviewLink;
use Psyao\Kami\Media\Media;
use Psyao\Kami\Sites\Internal\Site;

/**
 * Records one Audit event, in the caller's transaction when there is one: a rolled back action leaves no row (ADR 0022). Kami's core actions call it for what changes visibility or loses data; a site calls it for its own sensitive actions. Inside `AuditContext::batch()` it only counts the event towards the batch's summary.
 *
 * Kami's models are stored under short type names (`entry`, `media`, `preview-link`, a User as `user`), others under their morph class. The Site comes from the subject's `site` attribute.
 */
final class RecordAuditEvent
{
    private const int MAX_CONTEXT_KEYS = 20;

    private const int MAX_CONTEXT_STRING = 255;

    /**
     * @param  string  $event  A dotted name, such as `acme.invoices.exported`; `kami.` names Kami's own.
     * @param  Model|array{string, int|string}|null  $subject  The model the event is about, or its type and id once it is gone.
     * @param  array<string, scalar|null>  $context  A short flat object, such as a Locale or an old and new value; never a content diff.
     * @param  Authenticatable|null  $actor  Who acted: null records `cli` in the console and `system` otherwise.
     *
     * @throws InvalidArgumentException When the context holds more than 20 keys, a value that is not a scalar or null, or a string over 255 characters.
     */
    public function handle(string $event, Model | array | null $subject = null, array $context = [], ?Authenticatable $actor = null): void
    {
        self::refuseLongContext($context);
        $this->write($event, $subject, $context, $actor);
    }

    /**
     * @internal
     *
     * A batch's summary: `AuditContext::batch()` checked its context before the batch ran, and its counts are not held to the limit.
     *
     * @param  array<string, scalar|null>  $context
     */
    public function summarise(string $event, array $context): void
    {
        $this->write($event, null, $context, null);
    }

    /**
     * @internal
     *
     * @param  array<mixed>  $context
     *
     * @throws InvalidArgumentException
     */
    public static function refuseLongContext(array $context): void
    {
        if (count($context) > self::MAX_CONTEXT_KEYS) {
            throw new InvalidArgumentException('An Audit event’s context holds at most ' . self::MAX_CONTEXT_KEYS . ' keys.');
        }

        foreach ($context as $key => $value) {
            if (! is_string($key) || ($value !== null && ! is_scalar($value))) {
                throw new InvalidArgumentException("An Audit event’s context is a flat object of scalar values: [{$key}] is not.");
            }

            if (is_string($value) && mb_strlen($value) > self::MAX_CONTEXT_STRING) {
                throw new InvalidArgumentException("An Audit event’s context value [{$key}] is over " . self::MAX_CONTEXT_STRING . ' characters.');
            }
        }
    }

    /**
     * @param  Model|array{string, int|string}|null  $subject
     * @param  array<string, scalar|null>  $context
     */
    private function write(string $event, Model | array | null $subject, array $context, ?Authenticatable $actor): void
    {
        if (AuditContext::absorb($event)) {
            return;
        }

        [$subjectType, $subjectId, $site] = match (true) {
            $subject === null => [null, null, null],
            is_array($subject) => [$subject[0], (string) $subject[1], null],
            // Its Entry's Site: one Site until V1.
            $subject instanceof PreviewLink => ['preview-link', $subject->id, Site::current()->handle],
            default => [$this->type($subject), is_scalar($key = $subject->getKey()) ? (string) $key : null, $subject->hasAttribute('site') && is_string($site = $subject->getAttribute('site')) ? $site : null],
        };

        $console = App::runningInConsole();

        DB::table('kami_audit_events')->insert([
            'id' => (string) Str::ulid(),
            'occurred_at' => now()->utc()->format('Y-m-d H:i:s'),
            'user_id' => $actor?->getAuthIdentifier(),
            'actor' => match (true) {
                $actor instanceof Authenticatable => 'user',
                $console => 'cli',
                default => 'system',
            },
            'event' => $event,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'site' => $site,
            'ip' => $console ? null : $this->truncate(request()->ip()),
            'context' => json_encode((object) $context, JSON_THROW_ON_ERROR),
        ]);

        event(new AuditEventRecorded($event, $subjectType, $subjectId, $site, $context, $actor));
    }

    private function type(Model $subject): string
    {
        return match (true) {
            $subject instanceof Entry => 'entry',
            $subject instanceof Media => 'media',
            $subject instanceof Authenticatable => 'user',
            default => $subject->getMorphClass(),
        };
    }

    /** The network, not the connection: /24 for IPv4, /48 for IPv6 (Privacy over forensics, ADR 0022). */
    private function truncate(?string $ip): ?string
    {
        $packed = filter_var($ip, FILTER_VALIDATE_IP) === false ? false : inet_pton((string) $ip);

        if ($packed === false) {
            return null;
        }

        $kept = strlen($packed) === 4 ? 3 : 6;

        return inet_ntop(substr($packed, 0, $kept) . str_repeat("\0", strlen($packed) - $kept)) ?: null;
    }
}
