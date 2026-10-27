<?php

declare(strict_types=1);

namespace Modules\Leasing\Documents;

use App\Documents\Parts\DocumentDates;
use App\Documents\Verification\VerificationResolver;
use App\Documents\Verification\VerificationStatus;
use App\Documents\Verification\VerifiedDocument;
use App\Models\Organization;
use Modules\Leasing\Enums\LeaseStatus;
use Modules\Leasing\Models\Lease;
use Modules\Leasing\Models\LeaseContract;

/**
 * Lease contracts on the public verify page (P03-03b; kind
 * `lease_contract`, registered into DocumentVerifiers by
 * LeasingServiceProvider; BRANDING_DOCUMENTS.md § 5 "How it is built
 * (P03-05b)"). Runs inside the issuing organization.
 *
 * A contract is **valid** (genuine) whatever became of its lease — the page
 * says whether the lease is active or how it ended — and **superseded**
 * once a renewal took over (the renewal's contract number is shown). Only
 * the contract itself: number, issuer as printed, date of issue, term and
 * rent; never the tenant, the landlord or the address.
 */
final readonly class LeaseContractVerifier implements VerificationResolver
{
    public function __construct(private LeaseContracts $contracts) {}

    public function resolve(string $code, Organization $organization): ?VerifiedDocument
    {
        $contract = LeaseContract::query()->where('verification_code', $code)->first();
        $lease = $contract?->lease;

        if (! $contract instanceof LeaseContract || ! $lease instanceof Lease) {
            return null;
        }

        // The contract as printed: its issuer, term and rent as at issue.
        $printed = $this->contracts->document($contract);
        $replacedBy = $lease->status === LeaseStatus::Renewed
            ? Lease::query()->where('renewal_of_lease_id', $lease->id)->whereNotNull('contract_number')->orderBy('id')->value('contract_number')
            : null;

        return new VerifiedDocument(
            kind: LeaseContracts::KIND,
            title: 'Tenancy agreement',
            number: $contract->contract_number,
            issuer: $printed->brand->name(),
            issuedAt: $contract->issued_at,
            status: is_string($replacedBy) ? VerificationStatus::Superseded : VerificationStatus::Valid,
            details: [
                'Term' => $printed->termLabel(),
                'Rent' => $printed->rent->format().' '.$printed->cycleLabel(),
                'Lease' => $this->leaseState($lease),
            ],
            replacedBy: is_string($replacedBy) ? $replacedBy : null,
        );
    }

    private function leaseState(Lease $lease): string
    {
        return match ($lease->status) {
            LeaseStatus::Active => 'In force',
            LeaseStatus::Renewed => 'Renewed',
            LeaseStatus::Expired => 'Ended on '.DocumentDates::day($lease->end_date),
            LeaseStatus::Terminated => 'Ended early'.($lease->terminated_at !== null ? ' on '.DocumentDates::day($lease->terminated_at) : ''),
            LeaseStatus::Draft => 'Not in force',
        };
    }
}
