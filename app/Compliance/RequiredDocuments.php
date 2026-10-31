<?php

declare(strict_types=1);

namespace App\Compliance;

use App\Enums\ComplianceDocumentType;
use App\Enums\TransactionTier;
use App\Models\ComplianceDocument;
use App\Models\TierApplication;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * What a Transaction Tier application must carry before it can be
 * submitted (ONBOARDING_COMPLIANCE.md § 5.3): the required documents of the
 * tier asked for (the tier's version in force, `transaction_tiers`, edited in
 * Compliance → Tier settings, P06-08), each with at least one document that
 * is waiting for review or accepted and not expired, a proof of address
 * dated within 3 months, and for Tier 3 a named compliance contact. Tier 2
 * needs the SCUML certificate.
 *
 * Which version's documents count (docs/plans/P06-subtasks.md default 78):
 * today's, except for an application already with compliance (submitted or
 * under review), which keeps those in force on the day it was submitted, so a
 * change never blocks an application compliance is reviewing.
 */
final readonly class RequiredDocuments
{
    public function __construct(private TransactionTiers $tiers) {}

    /** @return list<ComplianceDocumentType> */
    public function required(TransactionTier $tier, ?CarbonInterface $day = null): array
    {
        return $this->tiers->terms($tier, $day)->requiredDocuments;
    }

    /** @return list<ComplianceDocumentType> */
    public function optional(TransactionTier $tier, ?CarbonInterface $day = null): array
    {
        return $this->tiers->terms($tier, $day)->optionalDocuments;
    }

    /** @return list<ComplianceDocumentType> */
    public function requiredFor(TierApplication $application): array
    {
        return $this->required($application->requested_tier, $this->dayFor($application));
    }

    /** @return list<ComplianceDocumentType> */
    public function optionalFor(TierApplication $application): array
    {
        return $this->optional($application->requested_tier, $this->dayFor($application));
    }

    /** Every type an application for the tier may carry, required first. */
    public function accepts(TransactionTier $tier, ComplianceDocumentType $type): bool
    {
        return in_array($type, [...$this->required($tier), ...$this->optional($tier)], true);
    }

    /** The day whose documents the application is checked against (null = today). */
    public function dayFor(TierApplication $application): ?CarbonInterface
    {
        return $application->status->isWithCompliance() ? $application->submitted_at : null;
    }

    public function needsComplianceContact(TransactionTier $tier): bool
    {
        return $tier === TransactionTier::Three;
    }

    /**
     * What still stops the application from being submitted, one sentence
     * each; empty when it is complete.
     *
     * @return list<string>
     */
    public function problems(TierApplication $application): array
    {
        $documents = $application->currentDocuments()->get();
        $day = $this->dayFor($application);
        $problems = [];

        foreach ($this->missing($application->requested_tier, $documents, $day) as $type) {
            $problems[] = "Upload the {$type->label()}.";
        }

        foreach ($this->required($application->requested_tier, $day) as $type) {
            $months = $type->maxAgeMonths();

            if ($months !== null && $this->usable($documents, $type)->isNotEmpty() && $this->recent($documents, $type, $months)->isEmpty()) {
                $problems[] = "The {$type->label()} must be dated within the last {$months} months. Upload a newer one.";
            }
        }

        if ($this->needsComplianceContact($application->requested_tier) && ! $this->hasComplianceContact($application)) {
            $problems[] = 'Name your compliance contact (name, email and phone number).';
        }

        return $problems;
    }

    /**
     * Required types with no usable document yet.
     *
     * @param  Collection<int, ComplianceDocument>  $documents
     * @return list<ComplianceDocumentType>
     */
    public function missing(TransactionTier $tier, Collection $documents, ?CarbonInterface $day = null): array
    {
        return array_values(array_filter(
            $this->required($tier, $day),
            fn (ComplianceDocumentType $type): bool => $this->usable($documents, $type)->isEmpty(),
        ));
    }

    public function hasComplianceContact(TierApplication $application): bool
    {
        $contact = $application->compliance_contact ?? [];

        return filled($contact['name'] ?? null) && filled($contact['email'] ?? null) && filled($contact['phone'] ?? null);
    }

    /**
     * Documents of the type that count: waiting for review or accepted, and
     * not past their expiry date.
     *
     * @param  Collection<int, ComplianceDocument>  $documents
     * @return Collection<int, ComplianceDocument>
     */
    private function usable(Collection $documents, ComplianceDocumentType $type): Collection
    {
        $today = now()->startOfDay();

        return $documents->filter(fn (ComplianceDocument $document): bool => $document->type === $type
            && $document->status->counts()
            && ($document->expires_at === null || $document->expires_at->greaterThanOrEqualTo($today)));
    }

    /**
     * @param  Collection<int, ComplianceDocument>  $documents
     * @return Collection<int, ComplianceDocument>
     */
    private function recent(Collection $documents, ComplianceDocumentType $type, int $months): Collection
    {
        $oldest = now()->startOfDay()->subMonthsNoOverflow($months);

        return $this->usable($documents, $type)
            ->filter(fn (ComplianceDocument $document): bool => $document->issued_at !== null && $document->issued_at->greaterThanOrEqualTo($oldest));
    }
}
