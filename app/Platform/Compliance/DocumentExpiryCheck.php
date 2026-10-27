<?php

declare(strict_types=1);

namespace App\Platform\Compliance;

use App\Compliance\ComplianceAudit;
use App\Enums\ComplianceDocumentStatus;
use App\Enums\TierApplicationStatus;
use App\Models\ComplianceDocument;
use App\Models\Organization;
use App\Notifications\ComplianceDocumentsExpiring;
use App\Tenancy\OrganizationContext;
use App\Tenancy\OrganizationOwners;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Daily document expiry tracking (ONBOARDING_COMPLIANCE.md § 5.3–5.4:
 * `document_expiring` / `document_expired`, reminders 30 days ahead),
 * scheduled with Schedule::call in routes/console.php. Looks at the
 * documents that count (waiting for review or accepted) of applications
 * that are open or approved, across organizations (so it lives in the
 * Platform namespace), then per organization, inside its context:
 *
 * - within `compliance.expiry_warning_days` of the expiry date: noted once
 *   (`expiring_notified_at`);
 * - past it: status `expired` (it no longer counts towards an open
 *   application), noted once (`expired_notified_at`).
 *
 * Each organization's owners get one email per run listing what changed,
 * and each change is in the audit log. Safe to re-run: a document is
 * flagged at most once per stage. The organization's tier is not changed:
 * the docs only ask for reminders and flags (re-verification is P06-09).
 * The console lists the flagged documents (Compliance → Document expiry).
 */
final readonly class DocumentExpiryCheck
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationOwners $owners,
        private ComplianceAudit $audit,
    ) {}

    /** @return array{expiring: int, expired: int} */
    public function run(): array
    {
        $today = now()->startOfDay();
        $warnUntil = $today->addDays($this->warningDays());
        $counts = ['expiring' => 0, 'expired' => 0];

        $organizationIds = self::due(ReviewDocument::query(), $today, $warnUntil)
            ->distinct()
            ->pluck('organization_id');

        foreach (Organization::query()->whereIn('id', $organizationIds)->orderBy('id')->get() as $organization) {
            $result = $this->context->run($organization, fn (): array => $this->forOrganization($organization, $today, $warnUntil));
            $counts['expiring'] += $result['expiring'];
            $counts['expired'] += $result['expired'];
        }

        return $counts;
    }

    /** @return array{expiring: int, expired: int} */
    private function forOrganization(Organization $organization, CarbonImmutable $today, CarbonImmutable $warnUntil): array
    {
        /** @var list<array{label: string, expires_at: string, expired: bool}> $lines */
        $lines = [];
        $counts = ['expiring' => 0, 'expired' => 0];

        DB::transaction(function () use ($today, $warnUntil, &$lines, &$counts): void {
            foreach ($this->candidates($today, $warnUntil) as $document) {
                /** @var CarbonImmutable $expiresAt */
                $expiresAt = $document->expires_at;
                $expired = $expiresAt->lessThan($today);

                if ($expired) {
                    $from = $document->status;
                    $document->forceFill(['status' => ComplianceDocumentStatus::Expired, 'expired_notified_at' => now(), 'expiring_notified_at' => $document->expiring_notified_at ?? now()])->save();
                    $this->audit->record('compliance_document.expired', $document, null, "The {$document->type->label()} expired on {$expiresAt->toDateString()}", [
                        'old' => ['status' => $from->value],
                        'attributes' => ['status' => ComplianceDocumentStatus::Expired->value],
                        'tier_application_id' => $document->tier_application_id,
                    ]);
                    $counts['expired']++;
                } else {
                    $document->forceFill(['expiring_notified_at' => now()])->save();
                    $this->audit->record('compliance_document.expiring', $document, null, "The {$document->type->label()} expires on {$expiresAt->toDateString()}", [
                        'tier_application_id' => $document->tier_application_id,
                        'expires_at' => $expiresAt->toDateString(),
                    ]);
                    $counts['expiring']++;
                }

                $lines[] = ['label' => $document->type->label(), 'expires_at' => $expiresAt->format('j M Y'), 'expired' => $expired];
            }
        });

        if ($lines !== []) {
            $notice = new ComplianceDocumentsExpiring($organization->name, $organization->slug, $lines);

            foreach ($this->owners->withEmail($organization) as $owner) {
                $owner->notify($notice);
            }
        }

        return $counts;
    }

    /** @return Collection<int, ComplianceDocument> */
    private function candidates(CarbonImmutable $today, CarbonImmutable $warnUntil): Collection
    {
        return self::due(ComplianceDocument::query(), $today, $warnUntil)
            ->orderBy('expires_at')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Documents that count, of open or approved applications, that reached
     * a stage they were not flagged for yet.
     *
     * @template TModel of ComplianceDocument|ReviewDocument
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function due(Builder $query, CarbonImmutable $today, CarbonImmutable $warnUntil): Builder
    {
        $applicationStatuses = [...array_map(static fn (TierApplicationStatus $status): string => $status->value, TierApplicationStatus::open()), TierApplicationStatus::Approved->value];

        return $query
            ->whereIn('status', [ComplianceDocumentStatus::Pending->value, ComplianceDocumentStatus::Accepted->value])
            ->whereNotNull('expires_at')
            ->where(fn (Builder $inner) => $inner
                ->where(fn (Builder $expired) => $expired->where('expires_at', '<', $today->toDateString())->whereNull('expired_notified_at'))
                ->orWhere(fn (Builder $expiring) => $expiring->where('expires_at', '<=', $warnUntil->toDateString())->whereNull('expiring_notified_at')))
            ->whereHas('application', fn (Builder $application) => $application->whereIn('status', $applicationStatuses));
    }

    private function warningDays(): int
    {
        $days = config('compliance.expiry_warning_days', 30);

        return is_numeric($days) ? max(1, (int) $days) : 30;
    }
}
