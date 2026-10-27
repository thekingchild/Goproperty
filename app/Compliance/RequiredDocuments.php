<?php

declare(strict_types=1);

namespace App\Compliance;

use App\Enums\ComplianceDocumentType;
use App\Enums\TransactionTier;
use App\Models\ComplianceDocument;
use App\Models\TierApplication;
use Illuminate\Support\Collection;

/**
 * What a Transaction Tier application must carry before it can be
 * submitted (ONBOARDING_COMPLIANCE.md § 5.3): the required documents of the
 * tier asked for (`config/tiers.php`, until the Tier settings screen of
 * P06-08 moves them to `transaction_tiers.required_documents`), each with
 * at least one document that is waiting for review or accepted and not
 * expired, a proof of address dated within 3 months, and for Tier 3 a
 * named compliance contact. Tier 2 needs the SCUML certificate.
 */
final class RequiredDocuments
{
    /** @return list<ComplianceDocumentType> */
    public function required(TransactionTier $tier): array
    {
        return $this->types($tier, 'required_documents');
    }

    /** @return list<ComplianceDocumentType> */
    public function optional(TransactionTier $tier): array
    {
        return $this->types($tier, 'optional_documents');
    }

    /** Every type an application for the tier may carry, required first. */
    public function accepts(TransactionTier $tier, ComplianceDocumentType $type): bool
    {
        return in_array($type, [...$this->required($tier), ...$this->optional($tier)], true);
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
        $problems = [];

        foreach ($this->missing($application->requested_tier, $documents) as $type) {
            $problems[] = "Upload the {$type->label()}.";
        }

        foreach ($this->required($application->requested_tier) as $type) {
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
    public function missing(TransactionTier $tier, Collection $documents): array
    {
        return array_values(array_filter(
            $this->required($tier),
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

    /** @return list<ComplianceDocumentType> */
    private function types(TransactionTier $tier, string $key): array
    {
        $values = config("tiers.tiers.{$tier->value}.{$key}", []);

        return array_values(array_map(
            static fn (mixed $value): ComplianceDocumentType => ComplianceDocumentType::from((string) $value),
            is_array($values) ? $values : [],
        ));
    }
}
