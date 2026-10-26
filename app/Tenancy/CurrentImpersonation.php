<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\ImpersonationSession;
use App\Models\User;
use App\Platform\Impersonation\ImpersonationMode;

/**
 * The support session the current customer request runs in, if any
 * (PLATFORM_CONSOLE.md § 6). Scoped: empty for every request and job. Set
 * by App\Http\Middleware\ResumeImpersonation from the customer session,
 * read by ImpersonationGuard.
 */
final class CurrentImpersonation
{
    /** Where the customer-side session keeps the session ID (never in the staff session). */
    public const string SESSION_KEY = 'impersonation.session_id';

    private ?ImpersonationSession $session = null;

    private bool $refusalRecorded = false;

    public function begin(ImpersonationSession $session): void
    {
        $this->session = $session;
        $this->refusalRecorded = false;
    }

    public function clear(): void
    {
        $this->session = null;
        $this->refusalRecorded = false;
    }

    public function active(): bool
    {
        return $this->session !== null;
    }

    public function session(): ?ImpersonationSession
    {
        return $this->session;
    }

    /** Is this the impersonated user (the checks apply to them only)? */
    public function isImpersonated(mixed $user): bool
    {
        return $this->session !== null && $user instanceof User && $user->getKey() === $this->session->user_id;
    }

    public function readOnly(): bool
    {
        return $this->session?->mode === ImpersonationMode::ReadOnly;
    }

    /** A refusal for this request is already in the audit log. */
    public function noteRefusal(): void
    {
        $this->refusalRecorded = true;
    }

    public function refusalRecorded(): bool
    {
        return $this->refusalRecorded;
    }
}
