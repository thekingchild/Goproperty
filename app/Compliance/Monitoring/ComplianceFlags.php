<?php

declare(strict_types=1);

namespace App\Compliance\Monitoring;

use App\Compliance\ComplianceAudit;
use App\Enums\ComplianceFlagStatus;
use App\Enums\ComplianceFlagType;
use App\Enums\TransactionTier;
use App\Models\ComplianceFlag;
use App\Tenancy\OrganizationContext;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Raises and closes the monitoring's compliance flags (P06-09;
 * ONBOARDING_COMPLIANCE.md § 5.4, defaults 89–92). Runs inside the
 * organization's context. A flag's `dedupe_key` (unique) makes every check
 * safe to re-run: a key already used raises nothing.
 *
 * Volume flags (`volume_80`, `forecast_over`, `volume_100`) are episodes:
 * one open flag per organization, type and tier while the measure stays
 * over its line; the monitoring resolves it when the measure falls back
 * (or the tier changes), and a later crossing opens the next episode
 * (`<type>:<organization>:t<tier>:<n>`).
 *
 * Every flag raised or resolved here is one audit entry (log `compliance`,
 * `compliance_flag.raised` / `compliance_flag.resolved`, no causer: the
 * system).
 */
final readonly class ComplianceFlags
{
    public function __construct(
        private OrganizationContext $context,
        private ComplianceAudit $audit,
    ) {}

    /**
     * Opens the flag unless its key was used; returns the new flag.
     *
     * @param  array<string, mixed>  $attributes  `compliance_flags` columns
     */
    public function raise(ComplianceFlagType $type, string $dedupeKey, array $attributes = []): ?ComplianceFlag
    {
        if (ComplianceFlag::query()->where('dedupe_key', $dedupeKey)->exists()) {
            return null;
        }

        $flag = new ComplianceFlag;
        $flag->forceFill([
            ...$attributes,
            'organization_id' => $this->context->id(),
            'type' => $type,
            'status' => ComplianceFlagStatus::Open,
            'dedupe_key' => $dedupeKey,
        ]);

        try {
            $flag->save();
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        $this->audit->record('compliance_flag.raised', $flag, null, "Flag raised: {$type->label()}", [
            'type' => $type->value,
            'tier' => $flag->tier?->value,
            'metric_value_kobo' => $flag->metric_value_kobo,
            'limit_kobo' => $flag->limit_kobo,
            'subject_type' => $flag->subject_type,
            'subject_id' => $flag->subject_id,
            'grace_ends_at' => $flag->grace_ends_at?->toIso8601String(),
        ]);

        return $flag;
    }

    /** The open (or acknowledged) episode of a volume flag for the tier, if any. */
    public function openEpisode(ComplianceFlagType $type, TransactionTier $tier): ?ComplianceFlag
    {
        return ComplianceFlag::query()
            ->where('type', $type->value)
            ->where('tier', $tier->value)
            ->whereIn('status', ComplianceFlagStatus::openValues())
            ->latest('id')
            ->first();
    }

    /**
     * Opens the next episode of a volume flag unless one is open; returns
     * the new flag (null when one was already open).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function openVolumeEpisode(ComplianceFlagType $type, TransactionTier $tier, array $attributes): ?ComplianceFlag
    {
        if ($this->openEpisode($type, $tier) instanceof ComplianceFlag) {
            return null;
        }

        $episode = ComplianceFlag::query()->where('type', $type->value)->where('tier', $tier->value)->count() + 1;

        return $this->raise($type, "{$type->value}:{$this->context->id()}:t{$tier->value}:{$episode}", [...$attributes, 'tier' => $tier]);
    }

    /**
     * Every open (or acknowledged) flag of the type, oldest first.
     *
     * @return list<ComplianceFlag>
     */
    public function openFlags(ComplianceFlagType $type): array
    {
        return array_values(ComplianceFlag::query()
            ->where('type', $type->value)
            ->whereIn('status', ComplianceFlagStatus::openValues())
            ->orderBy('id')
            ->get()
            ->all());
    }

    /** Closes a flag the monitoring no longer supports. */
    public function resolveBySystem(ComplianceFlag $flag, string $note): void
    {
        if (! $flag->isOpen()) {
            return;
        }

        $from = $flag->status;
        $flag->forceFill([
            'status' => ComplianceFlagStatus::Resolved,
            'resolution_note' => $note,
            'resolved_at' => now(),
            'resolved_by_staff_id' => null,
        ])->save();

        $this->audit->record('compliance_flag.resolved', $flag, null, "Flag resolved: {$flag->type->label()}", [
            'old' => ['status' => $from->value],
            'attributes' => ['status' => ComplianceFlagStatus::Resolved->value],
            'note' => $note,
        ]);
    }
}
