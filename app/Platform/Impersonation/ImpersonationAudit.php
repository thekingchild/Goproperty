<?php

declare(strict_types=1);

namespace App\Platform\Impersonation;

use App\Audit\AuditEntry;
use App\Models\ImpersonationSession;
use App\Models\Organization;
use App\Models\PlatformStaff;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Writes the impersonation trail to the hash-chained audit log
 * (PLATFORM_CONSOLE.md § 6–7), log name `impersonation`, with both
 * identities: the impersonated user as causer and the staff member in
 * `impersonator_staff_id`, plus the session ID, organization, IP and user
 * agent in the properties. Session entries have the session as subject, so
 * they make up its timeline in the console's impersonation log.
 *
 * Events: impersonation.started, .entered (the hand-off), .page_viewed,
 * .action, .blocked (a refused page, action or permission), .ended, and
 * .refused (a start or hand-off that was not allowed). Reading the log is
 * itself audited (.log_viewed, the reader as causer).
 */
final class ImpersonationAudit
{
    public const string LOG = 'impersonation';

    /** @param  array<string, mixed>  $properties */
    public function record(ImpersonationSession $session, string $event, string $description, array $properties = []): void
    {
        $user = $session->user;
        $staffId = $session->staff_id;

        activity(self::LOG)
            ->event($event)
            ->performedOn($session)
            ->causedBy($user)
            ->withProperties($this->context($session->id, $session->organization_id, $session->mode) + $properties)
            ->tap(function (mixed $entry) use ($staffId): void {
                if ($entry instanceof AuditEntry) {
                    $entry->impersonator_staff_id = $staffId;
                }
            })
            ->log($description);
    }

    /**
     * A start or hand-off that was refused, e.g. a staff member without the
     * permission. There may be no session yet: the user is the subject.
     */
    public function refused(PlatformStaff $staff, User $user, ?Organization $organization, ImpersonationMode $mode, string $reason, string $refusal, ?ImpersonationSession $session = null): void
    {
        $staffId = $staff->id;

        activity(self::LOG)
            ->event('impersonation.refused')
            ->performedOn($session ?? $user)
            ->causedBy($user)
            ->withProperties($this->context($session?->id, $organization?->id, $mode) + [
                'staff_id' => $staffId,
                'reason' => $reason !== '' ? Str::limit($reason, 500, '') : null,
                'refusal' => $refusal,
            ])
            ->tap(function (mixed $entry) use ($staffId): void {
                if ($entry instanceof AuditEntry) {
                    $entry->impersonator_staff_id = $staffId;
                }
            })
            ->log("Refused support access for {$staff->name}: {$refusal}");
    }

    /**
     * A staff member read the impersonation log (the list, or one session).
     *
     * @param  array<string, mixed>  $filters
     */
    public function logViewed(PlatformStaff $reader, ?ImpersonationSession $session = null, array $filters = []): void
    {
        $log = activity(self::LOG)
            ->event('impersonation.log_viewed')
            ->causedBy($reader)
            ->withProperties(array_filter([
                'session_id' => $session?->id,
                'filters' => $filters !== [] ? $filters : null,
            ], static fn (mixed $value): bool => $value !== null));

        if ($session !== null) {
            $log->performedOn($session);
        }

        $log->log($session !== null ? "Viewed impersonation session #{$session->id}" : 'Viewed the impersonation log');
    }

    /** @return array<string, mixed> */
    private function context(?int $sessionId, ?int $organizationId, ImpersonationMode $mode): array
    {
        $request = app()->bound('request') ? request() : null;

        return [
            'session_id' => $sessionId,
            'organization_id' => $organizationId,
            'mode' => $mode->value,
            'ip' => $request?->ip(),
            'user_agent' => $request?->userAgent() !== null ? Str::limit((string) $request->userAgent(), 250, '') : null,
        ];
    }
}
