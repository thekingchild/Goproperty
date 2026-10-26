<?php

declare(strict_types=1);

namespace App\Audit;

use Closure;

/**
 * Why an action happens and who is impersonating, for the audit entries
 * written meanwhile (PLATFORM_CONSOLE.md § 7). Scoped to the request.
 *
 *     app(AuditContext::class)->because('Tenant asked by phone', fn () => $lease->end());
 */
final class AuditContext
{
    private ?string $reason = null;

    private ?int $impersonatorStaffId = null;

    private ?int $impersonationSessionId = null;

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function because(string $reason, Closure $callback): mixed
    {
        $previous = $this->reason;
        $this->reason = $reason;

        try {
            return $callback();
        } finally {
            $this->reason = $previous;
        }
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    /**
     * Set for the whole request by a GoProperty support session
     * (App\Http\Middleware\ResumeImpersonation, PLATFORM_CONSOLE.md § 6):
     * every entry written meanwhile, business changes included, carries the
     * staff member in `impersonator_staff_id` and the session in its
     * properties (`impersonation_session_id`).
     */
    public function impersonatedBy(?int $staffId, ?int $sessionId = null): void
    {
        $this->impersonatorStaffId = $staffId;
        $this->impersonationSessionId = $staffId !== null ? $sessionId : null;
    }

    public function impersonatorStaffId(): ?int
    {
        return $this->impersonatorStaffId;
    }

    public function impersonationSessionId(): ?int
    {
        return $this->impersonationSessionId;
    }
}
