<?php

declare(strict_types=1);

namespace App\Models;

use App\Audit\AuditEntry;
use App\Platform\Impersonation\ImpersonationEndReason;
use App\Platform\Impersonation\ImpersonationMode;
use App\Policies\ImpersonationSessionPolicy;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One support session (PLATFORM_CONSOLE.md § 6, DATABASE_SCHEMA.md § 8): a
 * staff member viewing the customer app as a user, in one organization,
 * for at most 30 minutes. A platform record, not customer data: it has an
 * organization but is not organization-scoped, and the customer app only
 * ever reads the one session its browser holds. Changed only through
 * App\Platform\Impersonation\Impersonations; the pages viewed and actions
 * taken are audit entries with this session as subject.
 *
 * @property int $id
 * @property int $staff_id
 * @property int $user_id
 * @property int $organization_id
 * @property string $reason
 * @property ImpersonationMode $mode
 * @property int|null $approval_request_id
 * @property Carbon $started_at
 * @property Carbon $expires_at
 * @property string|null $handoff_token_hash
 * @property Carbon|null $handoff_expires_at
 * @property Carbon|null $handed_off_at
 * @property Carbon|null $ended_at
 * @property ImpersonationEndReason|null $ended_reason
 * @property int|null $ended_by_staff_id
 * @property string|null $ip
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PlatformStaff $staff
 * @property-read User $user
 * @property-read Organization $organization
 * @property-read ApprovalRequest|null $approvalRequest
 * @property-read PlatformStaff|null $endedBy
 */
#[Hidden(['handoff_token_hash'])]
#[UsePolicy(ImpersonationSessionPolicy::class)]
class ImpersonationSession extends Model
{
    protected $guarded = ['*'];

    /** @return BelongsTo<PlatformStaff, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'staff_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<ApprovalRequest, $this> */
    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    /** @return BelongsTo<PlatformStaff, $this> */
    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'ended_by_staff_id');
    }

    /**
     * The session's audit trail: start, hand-off, every page viewed, every
     * action, every blocked attempt and the end (log `impersonation`), and
     * every business change made during it (its own log, carrying the
     * session in `impersonation_session_id`). All of them carry the staff
     * member in `impersonator_staff_id`.
     *
     * @return HasMany<AuditEntry, $this>
     */
    public function timeline(): HasMany
    {
        $id = $this->getKey();

        return $this->hasMany(AuditEntry::class, 'impersonator_staff_id', 'staff_id')
            ->where(fn ($query) => $query
                ->where('properties->session_id', $id)
                ->orWhere('properties->impersonation_session_id', $id));
    }

    /** Not ended and within its 30 minutes. */
    public function isActive(): bool
    {
        return $this->ended_at === null && $this->expires_at->isFuture();
    }

    public function minutesLeft(): int
    {
        return $this->isActive() ? (int) ceil(now()->diffInSeconds($this->expires_at) / 60) : 0;
    }

    /** "Ada O." for "Ada Okafor": the staff member as the banner names them. */
    public static function shortName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = $parts[0] ?? '';
        $last = count($parts) > 1 ? end($parts) : '';

        return $last !== '' ? $first.' '.mb_strtoupper(mb_substr($last, 0, 1)).'.' : $first;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'mode' => ImpersonationMode::class,
            'ended_reason' => ImpersonationEndReason::class,
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'handoff_expires_at' => 'datetime',
            'handed_off_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
