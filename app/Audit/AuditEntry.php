<?php

declare(strict_types=1);

namespace App\Audit;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use LogicException;
use Spatie\Activitylog\Models\Activity;

/**
 * An audit log entry (PLATFORM_CONSOLE.md § 7): spatie's activity plus who
 * impersonated, from where (IP, user agent), why (reason), and its link in
 * the hash chain. Append-only: the app never updates or deletes entries.
 *
 * @property int|null $impersonator_staff_id
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string|null $reason
 * @property string|null $prev_hash
 * @property string|null $hash
 */
class AuditEntry extends Activity
{
    protected $table = AuditChain::TABLE;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Audit log entries are append-only and cannot be changed.'));
        static::deleting(fn (): never => throw new LogicException('Audit log entries are append-only and cannot be deleted.'));
    }

    /**
     * Fills the context, then inserts as the next link of the chain.
     *
     * @param  Builder<static>  $query
     */
    protected function performInsert(Builder $query): bool
    {
        $context = app(AuditContext::class);
        // Artisan's request has no client address, so jobs and commands store none.
        $request = app()->bound('request') ? request() : null;

        $this->ip ??= $request?->ip();
        $this->user_agent ??= $request?->userAgent() !== null ? Str::limit((string) $request->userAgent(), 250, '') : null;
        $this->reason ??= $context->reason();

        // During a support session every entry, business changes included,
        // names the staff member and the session (PLATFORM_CONSOLE.md § 6).
        if ($this->impersonator_staff_id === null && $context->impersonatorStaffId() !== null) {
            $this->impersonator_staff_id = $context->impersonatorStaffId();
            $properties = $this->properties ?? collect();

            if ($context->impersonationSessionId() !== null && ! $properties->has('impersonation_session_id')) {
                $this->properties = $properties->put('impersonation_session_id', $context->impersonationSessionId());
            }
        }

        // Fixed before hashing, so the stored time is the hashed time.
        $this->updateTimestamps();

        $inserted = false;

        app(AuditChain::class)->append($this->getAttributes(), function (array $chain) use ($query, &$inserted): void {
            $this->forceFill($chain);
            $inserted = parent::performInsert($query);
        });

        return $inserted;
    }
}
