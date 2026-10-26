<?php

declare(strict_types=1);

namespace App\Platform\Impersonation;

use App\Models\ApprovalRequest;
use App\Models\ImpersonationSession;
use App\Models\Organization;
use App\Models\PlatformStaff;
use App\Models\StaffStatus;
use App\Models\User;
use App\Platform\Approvals\ApprovalAction;
use App\Platform\Approvals\ApprovalService;
use App\Platform\Approvals\ApprovalStatus;
use App\Platform\Staff\StaffPermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Support impersonation (PLATFORM_CONSOLE.md § 6), the only writer of
 * `impersonation_sessions`:
 *
 * - start(): a staff member with `impersonate.read_only` (or, for write
 *   mode, `impersonate.write` plus a request a second staff member
 *   approved) picks a user and an organization they belong to and gives a
 *   reason. The session lasts at most 30 minutes from the start; the
 *   browser enters it through a one-time signed link valid 60 seconds.
 * - enter(): the hand-off. Checks the link and access again and opens the
 *   session; the customer side then runs a separate, time-limited session
 *   (App\Http\Middleware\ResumeImpersonation) that never signs the staff
 *   member in as the user.
 * - resume(): every customer request re-checks the session; one past its
 *   30 minutes ends with `timeout`, one whose access went away with
 *   `revoked`.
 * - end() / endByStaff() / endExpired().
 *
 * Every step, and every refusal, is in the audit log (ImpersonationAudit).
 * Nothing here emails or notifies the organization or the user: there is
 * no customer consent and no customer notification (§ 6).
 */
final class Impersonations
{
    public const int SESSION_MINUTES = 30;

    public const int HANDOFF_SECONDS = 60;

    /** How long an approved write-mode request may be used, from the approval. */
    public const int APPROVAL_USABLE_MINUTES = 60;

    public const int REASON_MAX = 500;

    public function __construct(
        private readonly ImpersonationAudit $audit,
        private readonly ApprovalService $approvals,
    ) {}

    /** May the staff member start sessions in this mode at all (the console's check)? */
    public function mayStart(PlatformStaff $staff, ImpersonationMode $mode): bool
    {
        return $staff->status === StaffStatus::Active && $staff->holdsStaffPermission($mode->permission());
    }

    /**
     * Starts a session and returns its one-time hand-off link. Write mode
     * needs the approved request (approvedWriteRequest()).
     *
     * @throws ImpersonationRefused
     */
    public function start(PlatformStaff $staff, User $user, Organization $organization, ImpersonationMode $mode, string $reason, ?ApprovalRequest $approval = null): HandOff
    {
        $reason = trim($reason);
        $refusal = $this->startRefusal($staff, $user, $organization, $mode, $reason) ?? $this->approvalRefusal($staff, $user, $organization, $mode, $approval);

        if ($refusal !== null) {
            $this->refuse($staff, $user, $organization, $mode, $reason, $refusal);
        }

        $outcome = DB::transaction(function () use ($staff, $user, $organization, $mode, $reason, $approval): HandOff|string {
            if ($approval !== null) {
                $locked = ApprovalRequest::query()->whereKey($approval->getKey())->lockForUpdate()->firstOrFail();
                $refusal = $this->approvalRefusal($staff, $user, $organization, $mode, $locked);

                if ($refusal !== null) {
                    return $refusal;
                }
            }

            // One session per staff member at a time.
            ImpersonationSession::query()
                ->where('staff_id', $staff->id)
                ->whereNull('ended_at')
                ->orderBy('id')
                ->get()
                ->each(fn (ImpersonationSession $open) => $this->end($open, ImpersonationEndReason::Manual, extra: ['superseded' => true]));

            $token = Str::random(48);
            $request = app()->bound('request') ? request() : null;

            $session = new ImpersonationSession;
            $session->forceFill([
                'staff_id' => $staff->id,
                'user_id' => $user->id,
                'organization_id' => $organization->id,
                'reason' => $reason,
                'mode' => $mode,
                'approval_request_id' => $approval?->getKey(),
                'started_at' => now(),
                'expires_at' => now()->addMinutes(self::SESSION_MINUTES),
                'handoff_token_hash' => hash('sha256', $token),
                'handoff_expires_at' => now()->addSeconds(self::HANDOFF_SECONDS),
                'ip' => $request?->ip(),
                'user_agent' => $request?->userAgent() !== null ? Str::limit((string) $request->userAgent(), 250, '') : null,
            ])->save();
            $session->setRelation('user', $user);

            $this->audit->record($session, 'impersonation.started', "{$staff->name} started a {$mode->label()} support session as {$user->name} in {$organization->name}", [
                'reason' => $reason,
                'approval_request_id' => $approval?->getKey(),
                'expires_at' => $session->expires_at->toIso8601String(),
            ]);

            return new HandOff($session, $this->handOffUrl($session, $token));
        });

        if (is_string($outcome)) {
            $this->refuse($staff, $user, $organization, $mode, $reason, $outcome);
        }

        return $outcome;
    }

    /**
     * Asks a second staff member to approve a write-mode session (two-person
     * rule, § 1). The approved request is then used once by start().
     *
     * @throws ImpersonationRefused
     */
    public function requestWrite(PlatformStaff $staff, User $user, Organization $organization, string $reason): ApprovalRequest
    {
        $reason = trim($reason);
        $refusal = $this->startRefusal($staff, $user, $organization, ImpersonationMode::Write, $reason);

        if ($refusal !== null) {
            $this->refuse($staff, $user, $organization, ImpersonationMode::Write, $reason, $refusal);
        }

        return $this->approvals->request(
            ApprovalAction::WriteImpersonation,
            $staff,
            $user,
            "Write-mode support session as {$user->name} in {$organization->name}",
            ['user_id' => $user->id, 'organization_id' => $organization->id],
            $reason,
        );
    }

    /**
     * The staff member's newest approved, unused write-mode request for this
     * user, while it may still be used.
     */
    public function approvedWriteRequest(PlatformStaff $staff, int $userId): ?ApprovalRequest
    {
        return ApprovalRequest::query()
            ->where('action', ApprovalAction::WriteImpersonation)
            ->where('status', ApprovalStatus::Approved)
            ->where('requested_by_staff_id', $staff->id)
            ->where('decided_at', '>', now()->subMinutes(self::APPROVAL_USABLE_MINUTES))
            ->whereNotIn('id', ImpersonationSession::query()->whereNotNull('approval_request_id')->select('approval_request_id'))
            ->orderByDesc('id')
            ->get()
            ->first(fn (ApprovalRequest $request): bool => (int) ($request->payload['user_id'] ?? 0) === $userId);
    }

    /**
     * The hand-off: opens the session the link belongs to, once, within its
     * 60 seconds, if the link's signature holds and the staff member and the
     * user still qualify.
     *
     * @throws ImpersonationRefused
     */
    public function enter(int $sessionId, string $token, bool $signatureValid = true): ImpersonationSession
    {
        $outcome = DB::transaction(function () use ($sessionId, $token, $signatureValid): ImpersonationSession|array {
            $session = ImpersonationSession::query()->whereKey($sessionId)->lockForUpdate()->first();

            $refusal = match (true) {
                $session === null => 'This support link is not valid.',
                $session->handed_off_at !== null => 'This support link was already used.',
                ! $signatureValid => 'This support link has expired. Start the session again from the console.',
                $session->handoff_token_hash === null || ! hash_equals($session->handoff_token_hash, hash('sha256', $token)) => 'This support link is not valid.',
                $session->handoff_expires_at === null || ! $session->handoff_expires_at->isFuture() => 'This support link has expired. Start the session again from the console.',
                ! $session->isActive() => 'This support session has ended.',
                default => $this->accessRefusal($session),
            };

            if ($refusal !== null) {
                return [$session, $refusal];
            }

            /** @var ImpersonationSession $session */
            $session->forceFill(['handed_off_at' => now(), 'handoff_token_hash' => null])->save();
            $this->audit->record($session, 'impersonation.entered', "{$session->staff->name} opened the support session as {$session->user->name}");

            return $session;
        });

        if (is_array($outcome)) {
            [$session, $refusal] = $outcome;

            if ($session !== null) {
                $this->audit->refused($session->staff, $session->user, $session->organization, $session->mode, $session->reason, $refusal, $session);
            }

            throw new ImpersonationRefused($refusal);
        }

        return $outcome;
    }

    /**
     * The open session a customer request carries, or null when it is over.
     * Ends it with `timeout` once its 30 minutes are up, and with `revoked`
     * when the staff member or the user no longer qualifies.
     */
    public function resume(int $sessionId): ?ImpersonationSession
    {
        $session = ImpersonationSession::query()->with(['staff', 'user', 'organization'])->find($sessionId);

        if ($session === null || $session->ended_at !== null || $session->handed_off_at === null) {
            return null;
        }

        if (! $session->expires_at->isFuture()) {
            $this->end($session, ImpersonationEndReason::Timeout);

            return null;
        }

        $refusal = $this->accessRefusal($session);

        if ($refusal !== null) {
            $this->end($session, ImpersonationEndReason::Revoked, extra: ['cause' => $refusal]);

            return null;
        }

        return $session;
    }

    /**
     * Ends a session (once; later calls change nothing).
     *
     * @param  array<string, mixed>  $extra
     */
    public function end(ImpersonationSession $session, ImpersonationEndReason $reason, ?PlatformStaff $by = null, array $extra = []): bool
    {
        return DB::transaction(function () use ($session, $reason, $by, $extra): bool {
            $locked = ImpersonationSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->ended_at !== null) {
                $session->setRawAttributes($locked->getAttributes(), true);

                return false;
            }

            $locked->forceFill([
                'ended_at' => now(),
                'ended_reason' => $reason,
                'ended_by_staff_id' => $by?->id,
                'handoff_token_hash' => null,
            ])->save();

            $this->audit->record($locked, 'impersonation.ended', "Support session ended: {$reason->label()}", array_filter([
                'ended_reason' => $reason->value,
                'ended_by_staff_id' => $by?->id,
                'handed_off' => $locked->handed_off_at !== null,
                'duration_seconds' => (int) $locked->started_at->diffInSeconds(now()),
            ], static fn (mixed $value): bool => $value !== null) + $extra);

            $session->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    /**
     * A super-admin ends someone's active session from the console.
     *
     * @throws ImpersonationRefused
     */
    public function endByStaff(PlatformStaff $actor, ImpersonationSession $session): void
    {
        if (! $actor->isSuperAdmin() || ! $actor->holdsStaffPermission(StaffPermission::ImpersonationViewLog)) {
            throw new ImpersonationRefused('Only a super-admin can end another staff member\'s session.');
        }

        if (! $this->end($session, ImpersonationEndReason::EndedByStaff, $actor)) {
            throw new ImpersonationRefused('This session has already ended.');
        }
    }

    /**
     * Ends every session past its 30 minutes, and every one whose hand-off
     * link ran out unused (scheduler, every minute).
     *
     * @return int how many ended
     */
    public function endExpired(): int
    {
        $ended = 0;

        ImpersonationSession::query()
            ->whereNull('ended_at')
            ->where(fn ($query) => $query
                ->where('expires_at', '<=', now())
                ->orWhere(fn ($unused) => $unused->whereNull('handed_off_at')->where('handoff_expires_at', '<=', now())))
            ->orderBy('id')
            ->each(function (ImpersonationSession $session) use (&$ended): void {
                if ($this->end($session, ImpersonationEndReason::Timeout)) {
                    $ended++;
                }
            });

        return $ended;
    }

    /** Why this staff member may not start (or ask for) a session on this user, or null. */
    private function startRefusal(PlatformStaff $staff, User $user, Organization $organization, ImpersonationMode $mode, string $reason): ?string
    {
        return match (true) {
            ! $this->mayStart($staff, $mode) => $mode === ImpersonationMode::Write
                ? 'You do not have permission to impersonate in write mode.'
                : 'You do not have permission to impersonate users.',
            $reason === '' => 'Give a reason (for example the support ticket number): it is kept in the audit log.',
            mb_strlen($reason) > self::REASON_MAX => 'The reason is too long.',
            $this->isStaff($user) => 'Staff accounts cannot be impersonated.',
            ! $user->belongsToOrganization($organization) => "{$user->name} is not a member of {$organization->name}.",
            default => null,
        };
    }

    /** Write mode needs this staff member's own approved, unused request for this user and organization. */
    private function approvalRefusal(PlatformStaff $staff, User $user, Organization $organization, ImpersonationMode $mode, ?ApprovalRequest $approval): ?string
    {
        if (! $mode->needsApproval()) {
            return $approval === null ? null : 'A read-only session does not use an approval.';
        }

        return match (true) {
            $approval === null => 'A write-mode session needs a second staff member\'s approval first.',
            $approval->action !== ApprovalAction::WriteImpersonation,
            $approval->requested_by_staff_id !== $staff->id,
            (int) ($approval->payload['user_id'] ?? 0) !== $user->id,
            (int) ($approval->payload['organization_id'] ?? 0) !== $organization->id => 'That approval is for another session.',
            $approval->status !== ApprovalStatus::Approved => 'That request has not been approved.',
            $approval->decided_at === null || $approval->decided_at->lte(now()->subMinutes(self::APPROVAL_USABLE_MINUTES)) => 'That approval has expired: ask again.',
            ImpersonationSession::query()->where('approval_request_id', $approval->getKey())->exists() => 'That approval was already used: ask again.',
            default => null,
        };
    }

    /** Whether the staff member and the user still qualify during a session. */
    private function accessRefusal(ImpersonationSession $session): ?string
    {
        $staff = $session->staff;
        $user = $session->user;
        $organization = $session->organization;

        return match (true) {
            ! $this->mayStart($staff, $session->mode) => 'The staff member no longer has permission to impersonate.',
            $this->isStaff($user) => 'Staff accounts cannot be impersonated.',
            ! $user->belongsToOrganization($organization) => "{$user->name} is no longer a member of {$organization->name}.",
            default => null,
        };
    }

    /** A customer account with a staff member's email: never impersonated (§ 6, "can't impersonate other staff"). */
    private function isStaff(User $user): bool
    {
        return $user->email !== null
            && PlatformStaff::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($user->email)])->exists();
    }

    /** @throws ImpersonationRefused */
    private function refuse(PlatformStaff $staff, User $user, Organization $organization, ImpersonationMode $mode, string $reason, string $refusal): never
    {
        $this->audit->refused($staff, $user, $organization, $mode, $reason, $refusal);

        throw new ImpersonationRefused($refusal);
    }

    /**
     * A one-time link on the customer site (never the console host), signed
     * and valid 60 seconds; the token is checked against its hash too.
     */
    private function handOffUrl(ImpersonationSession $session, string $token): string
    {
        $path = URL::temporarySignedRoute(
            'impersonation.enter',
            now()->addSeconds(self::HANDOFF_SECONDS),
            ['session' => $session->id, 'token' => $token],
            absolute: false,
        );

        return rtrim((string) config('app.url'), '/').$path;
    }
}
