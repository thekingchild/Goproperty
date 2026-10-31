<?php

declare(strict_types=1);

namespace App\Platform\Compliance;

use App\Compliance\ComplianceAudit;
use App\Compliance\TransactionTiers;
use App\Enums\TierVersionStatus;
use App\Enums\TransactionTier;
use App\Models\ApprovalRequest;
use App\Models\PlatformStaff;
use App\Models\TransactionTierVersion;
use App\Platform\Approvals\ApprovalAction;
use App\Platform\Approvals\ApprovalRefused;
use App\Platform\Approvals\ApprovalService;
use App\Platform\Approvals\ApprovalStatus;
use App\Platform\Staff\StaffPermission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Changes to the Transaction Tiers' limits and documents under the two-person
 * rule (P06-08; ONBOARDING_COMPLIANCE.md § 5.2 "Editing the limits",
 * PLATFORM_CONSOLE.md § 1A; docs/plans/P06-subtasks.md defaults 72–77). The
 * only writer of `transaction_tiers`.
 *
 * - propose(): a tier's whole new terms as its next version (`pending`), from
 *   a Lagos day (today up to a year ahead), with a reason; needs
 *   `tiers.configure` and opens an approval request (ApprovalAction::
 *   TierSettings) that only another staff member with `tiers.approve_settings`
 *   may decide. One proposal waits per tier.
 * - approve() (through TierSettingsApproval, inside the approval's
 *   transaction): the version is approved and applies from its date, or from
 *   the approval's day when that is later (never backdated).
 * - notApproved(): a rejected or expired request leaves the version
 *   `rejected` / `expired`; it never applies.
 * - cancel(): an approved version whose date has not come (`tiers.configure`,
 *   a reason); the terms in force stay.
 *
 * Every step is one audit entry (log `compliance`, the version as subject)
 * with the staff member, the reason and the terms before and after.
 */
final readonly class TierSettings
{
    public const int REASON_MIN = 3;

    public const int REASON_MAX = 500;

    public const int NAME_MAX = 60;

    /** ₦1 trillion: the largest amount staff may set (default 77). */
    public const int MAX_KOBO = 100_000_000_000_000;

    public const int MAX_REVIEW_DAYS = 60;

    public function __construct(
        private TransactionTiers $tiers,
        private ApprovalService $approvals,
        private ComplianceAudit $audit,
    ) {}

    /**
     * Problems that block the draft, one sentence each; empty when it may be
     * proposed.
     *
     * @return list<string>
     */
    public function problems(TierDraft $draft): array
    {
        $problems = [];
        $name = trim($draft->name);

        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            $problems[] = 'Give the tier a name of up to '.self::NAME_MAX.' characters.';
        }

        foreach ([
            'yearly limit' => $draft->annualLimitKobo,
            'single-payment limit' => $draft->singlePaymentLimitKobo,
            'review amount' => $draft->reviewThresholdKobo,
        ] as $label => $kobo) {
            if ($kobo !== null && ($kobo < 100 || $kobo > self::MAX_KOBO)) {
                $problems[] = "The {$label} is between ₦1 and ₦1,000,000,000,000, or empty.";
            }
        }

        if ($draft->singlePaymentLimitKobo !== null && $draft->annualLimitKobo !== null && $draft->singlePaymentLimitKobo > $draft->annualLimitKobo) {
            $problems[] = 'The single-payment limit cannot be above the yearly limit.';
        }

        if ($draft->singlePaymentLimitKobo === null && $draft->annualLimitKobo !== null) {
            $problems[] = 'A tier with a yearly limit needs a single-payment limit too.';
        }

        if ($draft->reviewThresholdKobo !== null && $draft->singlePaymentLimitKobo !== null && $draft->reviewThresholdKobo > $draft->singlePaymentLimitKobo) {
            $problems[] = 'The review amount cannot be above the single-payment limit: a payment over the limit is flagged anyway.';
        }

        if (array_uintersect($draft->requiredDocuments, $draft->optionalDocuments, static fn ($a, $b): int => strcmp($a->value, $b->value)) !== []) {
            $problems[] = 'A document is either required or optional, not both.';
        }

        if ($draft->tier === TransactionTier::One) {
            if ($draft->requiredDocuments !== [] || $draft->optionalDocuments !== []) {
                $problems[] = 'Tier 1 needs no application, so it has no documents.';
            }

            if ($draft->reviewSlaDays !== null) {
                $problems[] = 'Tier 1 is active straight away: it has no review target.';
            }
        } else {
            if ($draft->requiredDocuments === []) {
                $problems[] = "{$draft->tier->label()} must require at least one document.";
            }

            if ($draft->reviewSlaDays === null || $draft->reviewSlaDays < 1 || $draft->reviewSlaDays > self::MAX_REVIEW_DAYS) {
                $problems[] = 'Give the review target in business days, from 1 to '.self::MAX_REVIEW_DAYS.'.';
            }
        }

        return $problems;
    }

    /**
     * Things the proposer should know but that do not block it: the tiers'
     * order (default 77) and lowered limits (default 76).
     *
     * @return list<string>
     */
    public function warnings(TierDraft $draft): array
    {
        $warnings = [];
        $current = TierDraft::of($this->tiers->terms($draft->tier));
        $lowered = $draft->lowers($current);

        if ($lowered !== []) {
            $warnings[] = 'This lowers the '.implode(' and the ', $lowered).'. Nobody is restricted at once: organizations over the new limit get 60 days to apply for an upgrade (from the monitoring of P06-09).';
        }

        foreach (TransactionTier::cases() as $other) {
            if ($other === $draft->tier) {
                continue;
            }

            $limit = $this->tiers->terms($other)->annualLimit?->kobo;
            $mine = $draft->annualLimitKobo;
            $outOfOrder = $other->value < $draft->tier->value
                ? ($mine !== null && ($limit === null || $mine < $limit))
                : ($limit !== null && ($mine === null || $limit < $mine));

            if ($outOfOrder) {
                $warnings[] = "The yearly limit is out of order with {$other->label()}'s (".TierDraft::amount($limit).').';
            }
        }

        return $warnings;
    }

    /**
     * @param  string  $effectiveFrom  a Lagos date, Y-m-d
     *
     * @throws TierSettingsRefused
     */
    public function propose(PlatformStaff $staff, TierDraft $draft, string $effectiveFrom, string $reason): TransactionTierVersion
    {
        $this->authorize($staff, StaffPermission::TiersConfigure, 'You do not have permission to change the Transaction Tier settings.');
        $reason = $this->reason($reason);
        $day = $this->effectiveDay($effectiveFrom);
        $draft = new TierDraft($draft->tier, trim($draft->name), $draft->annualLimitKobo, $draft->singlePaymentLimitKobo, $draft->reviewThresholdKobo, $draft->requiredDocuments, $draft->optionalDocuments, $draft->reviewSlaDays);
        $problems = $this->problems($draft);

        if ($problems !== []) {
            throw new TierSettingsRefused($problems[0]);
        }

        return DB::transaction(function () use ($staff, $draft, $day, $reason): TransactionTierVersion {
            // One proposal at a time per tier: its versions are locked while the next is numbered.
            $versions = TransactionTierVersion::query()->with('approvalRequest')->where('tier', $draft->tier->value)->lockForUpdate()->get();
            $waiting = $versions->first(fn (TransactionTierVersion $version): bool => $version->status === TierVersionStatus::Pending
                && ($version->approvalRequest === null || ! $version->approvalRequest->hasExpired()));

            if ($waiting instanceof TransactionTierVersion) {
                throw new TierSettingsRefused("Version {$waiting->version} of {$draft->tier->label()} is still waiting for approval: it must be approved or rejected first.");
            }

            $current = TierDraft::of($this->tiers->terms($draft->tier));
            $changes = $draft->changesFrom($current);

            if ($changes === []) {
                throw new TierSettingsRefused('Nothing changes: these are the terms in force.');
            }

            $version = new TransactionTierVersion;
            $version->forceFill([
                ...$this->columns($draft),
                'tier' => $draft->tier,
                'version' => ((int) $versions->max('version')) + 1,
                'effective_from' => $day->toDateString(),
                'status' => TierVersionStatus::Pending,
                'reason' => $reason,
                'proposed_by_staff_id' => $staff->id,
            ])->save();

            $from = $day->format('j M Y');
            $lowered = $draft->lowers($current);
            $summary = "{$draft->tier->label()} v{$version->version} from {$from}".($lowered !== [] ? ' (lowers the '.implode(' and ', $lowered).')' : '').': '.implode('; ', $changes);

            try {
                $request = $this->approvals->request(ApprovalAction::TierSettings, $staff, $version, $summary, [
                    'transaction_tier_version_id' => $version->id,
                    'tier' => $draft->tier->value,
                    'version' => $version->version,
                ], $reason);
            } catch (ApprovalRefused $refused) {
                throw new TierSettingsRefused($refused->getMessage(), previous: $refused);
            }

            $version->forceFill(['approval_request_id' => $request->id])->save();

            $this->audit->record('tier_settings.proposed', $version, $staff, "Proposed {$draft->tier->label()} terms, version {$version->version}, from {$from}", [
                'tier' => $draft->tier->value,
                'version' => $version->version,
                'effective_from' => $day->toDateString(),
                'changes' => $changes,
                'lowers' => $lowered,
                'old' => $current->toArray(),
                'attributes' => $draft->toArray(),
                'approval_request_id' => $request->id,
            ], $reason);

            return $version;
        }, 3);
    }

    /**
     * Called by TierSettingsApproval inside the approval's transaction (the
     * approver's permission and "not the proposer" are checked there).
     *
     * @throws ApprovalRefused
     */
    public function approve(ApprovalRequest $request, PlatformStaff $approver): TransactionTierVersion
    {
        $version = $this->versionOf($request);

        if ($version->status !== TierVersionStatus::Pending) {
            throw new ApprovalRefused("Version {$version->version} of {$version->tier->label()} is no longer waiting for approval.");
        }

        $today = CarbonImmutable::parse(TransactionTiers::day(), TransactionTiers::TIMEZONE)->startOfDay();
        $proposed = $version->effective_from->toDateString();
        $applies = $proposed < $today->toDateString() ? $today : CarbonImmutable::parse($proposed, TransactionTiers::TIMEZONE);

        $version->forceFill([
            'status' => TierVersionStatus::Approved,
            'approved_by_staff_id' => $approver->id,
            'approved_at' => CarbonImmutable::now(),
            'effective_from' => $applies->toDateString(),
        ])->save();

        $this->audit->record('tier_settings.approved', $version, $approver, "Approved {$version->tier->label()} terms, version {$version->version}, from {$applies->format('j M Y')}", array_filter([
            'tier' => $version->tier->value,
            'version' => $version->version,
            'effective_from' => $applies->toDateString(),
            'proposed_effective_from' => $proposed !== $applies->toDateString() ? $proposed : null,
            'approval_request_id' => $request->id,
            'proposed_by_staff_id' => $version->proposed_by_staff_id,
        ], static fn (mixed $value): bool => $value !== null));

        return $version;
    }

    /** A rejected or expired request: the version never applies. */
    public function notApproved(ApprovalRequest $request): void
    {
        try {
            $version = $this->versionOf($request);
        } catch (ApprovalRefused) {
            return;
        }

        if ($version->status !== TierVersionStatus::Pending) {
            return;
        }

        // On expiry the request is already marked; on a rejection it is marked after this call.
        $status = $request->status === ApprovalStatus::Expired ? TierVersionStatus::Expired : TierVersionStatus::Rejected;
        $version->forceFill(['status' => $status])->save();

        $this->audit->record('tier_settings.not_approved', $version, null, "{$version->tier->label()} terms, version {$version->version}, were not approved ({$status->label()})", [
            'tier' => $version->tier->value,
            'version' => $version->version,
            'status' => $status->value,
            'approval_request_id' => $request->id,
        ]);
    }

    /** @throws TierSettingsRefused */
    public function cancel(PlatformStaff $staff, TransactionTierVersion $version, string $reason): TransactionTierVersion
    {
        $this->authorize($staff, StaffPermission::TiersConfigure, 'You do not have permission to change the Transaction Tier settings.');
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($staff, $version, $reason): TransactionTierVersion {
            $locked = TransactionTierVersion::query()->whereKey($version->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isScheduled()) {
                throw new TierSettingsRefused('Only an approved version whose date has not come can be cancelled.');
            }

            $locked->forceFill([
                'status' => TierVersionStatus::Cancelled,
                'cancelled_by_staff_id' => $staff->id,
                'cancelled_at' => CarbonImmutable::now(),
            ])->save();

            $this->audit->record('tier_settings.cancelled', $locked, $staff, "Cancelled {$locked->tier->label()} terms, version {$locked->version}, due from {$locked->effective_from->format('j M Y')}", [
                'tier' => $locked->tier->value,
                'version' => $locked->version,
                'effective_from' => $locked->effective_from->toDateString(),
            ], $reason);

            $version->setRawAttributes($locked->getAttributes(), true);

            return $version;
        }, 3);
    }

    /** The earliest and latest effective dates a proposal may take today (Lagos). */
    public static function firstDay(): CarbonImmutable
    {
        return CarbonImmutable::parse(TransactionTiers::day(), TransactionTiers::TIMEZONE)->startOfDay();
    }

    public static function lastDay(): CarbonImmutable
    {
        return self::firstDay()->addYearNoOverflow();
    }

    /** @return array<string, mixed> */
    private function columns(TierDraft $draft): array
    {
        $values = $draft->toArray();

        return [
            'name' => $values['name'],
            'annual_volume_limit_kobo' => $values['annual_volume_limit_kobo'],
            'single_payment_limit_kobo' => $values['single_payment_limit_kobo'],
            'review_threshold_kobo' => $values['review_threshold_kobo'],
            'required_documents' => $values['required_documents'],
            'optional_documents' => $values['optional_documents'],
            'review_sla_days' => $values['review_sla_days'],
        ];
    }

    /** @throws ApprovalRefused */
    private function versionOf(ApprovalRequest $request): TransactionTierVersion
    {
        $id = $request->payload['transaction_tier_version_id'] ?? null;
        $version = is_numeric($id) ? TransactionTierVersion::query()->whereKey((int) $id)->lockForUpdate()->first() : null;

        return $version ?? throw new ApprovalRefused('This request names no Transaction Tier version.');
    }

    /** @throws TierSettingsRefused */
    private function effectiveDay(string $date): CarbonImmutable
    {
        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, TransactionTiers::TIMEZONE);
        } catch (Throwable) {
            $day = null;
        }

        if (! $day instanceof CarbonImmutable || $day->format('Y-m-d') !== $date) {
            throw new TierSettingsRefused('Give the date the change applies from.');
        }

        if ($day->lessThan(self::firstDay()) || $day->greaterThan(self::lastDay())) {
            throw new TierSettingsRefused('A change applies from today at the earliest and at most a year ahead.');
        }

        return $day;
    }

    /** @throws TierSettingsRefused */
    private function authorize(PlatformStaff $staff, StaffPermission $permission, string $message): void
    {
        if (! $staff->holdsStaffPermission($permission)) {
            throw new TierSettingsRefused($message);
        }
    }

    /** @throws TierSettingsRefused */
    private function reason(string $reason): string
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < self::REASON_MIN || mb_strlen($reason) > self::REASON_MAX) {
            throw new TierSettingsRefused('Give a reason of '.self::REASON_MIN.' to '.self::REASON_MAX.' characters: it is kept with the change and in the audit log.');
        }

        return $reason;
    }
}
