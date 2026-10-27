<?php

declare(strict_types=1);

namespace Modules\Leasing\Documents;

use App\Documents\Document;
use App\Documents\DocumentBranding;
use App\Documents\Parts\DocumentDates;
use App\Documents\Parts\DocumentProperty;
use App\Documents\Parts\DocumentTenant;
use App\Documents\Verification\VerificationCodes;
use App\Support\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Modules\Leasing\Contracts\BillingCycle;

/**
 * A lease / tenancy agreement as printed (BRANDING_DOCUMENTS.md § 4:
 * parties, property, term, cycle, rent and charges schedule, deposit,
 * clauses from the agency's template, signature blocks; ARCHITECTURE.md
 * § 6.5), in the organization's layout
 * (Modules/Leasing/resources/views/documents/contract/{modern,classic,compact}).
 * LeaseContracts builds one from an issued contract (Models\LeaseContract:
 * the branding snapshot, parties, schedule and clause template as at
 * issue) and renders it with App\Documents\DocumentRenderer.
 *
 * The clauses come from the agency's template with only the whitelisted
 * placeholders filled in (ContractClauses::render()), as plain text that
 * the view escapes: nothing an agency types is ever run as a template
 * (AGENT.md § 3 rule 10).
 */
final readonly class LeaseContractDocument implements Document
{
    /**
     * @param  ?string  $landlord  the building's owner, when one is recorded
     * @param  CarbonImmutable  $firstDue  when the first rent falls due
     * @param  ?string  $renewalOf  the contract number of the lease this one renews
     * @param  string  $clauseTemplate  the agency's clause template as at issue (ContractClauses)
     */
    public function __construct(
        public DocumentBranding $brand,
        public string $number,
        public CarbonImmutable $issuedAt,
        public DocumentTenant $tenant,
        public DocumentProperty $property,
        public ?string $landlord,
        public BillingCycle $cycle,
        public CarbonImmutable $startDate,
        public CarbonImmutable $endDate,
        public Money $rent,
        public Money $deposit,
        public int $dueDayOffset,
        public CarbonImmutable $firstDue,
        public ?CarbonImmutable $paidUntil,
        public ?string $renewalOf,
        public string $clauseTemplate,
        public string $verificationCode,
    ) {
        if (VerificationCodes::normalize($verificationCode) === null) {
            throw new InvalidArgumentException('A lease contract needs its verification code (VerificationCodes::issue()).');
        }

        if ($endDate->lessThan($startDate)) {
            throw new InvalidArgumentException('A lease contract ends on or after the day it starts.');
        }
    }

    public function view(): string
    {
        return 'leasing::documents.contract.'.$this->brand->layout()->value;
    }

    public function data(): array
    {
        return ['contract' => $this];
    }

    public function brand(): DocumentBranding
    {
        return $this->brand;
    }

    public function fileName(): string
    {
        return $this->number.'.pdf';
    }

    /** The landlord as printed: the owner, or the agency when none is recorded. */
    public function landlordName(): string
    {
        return $this->landlord !== null && trim($this->landlord) !== '' ? $this->landlord : $this->brand->name();
    }

    /** True when the agency signs as managing agent for an owner. */
    public function managedForOwner(): bool
    {
        return $this->landlordName() !== $this->brand->name();
    }

    public function issuedOnLabel(): string
    {
        return DocumentDates::day($this->issuedAt);
    }

    /** "1 Jan 2027 to 31 Dec 2027" */
    public function termLabel(): string
    {
        return DocumentDates::long($this->startDate).' to '.DocumentDates::long($this->endDate);
    }

    /** "yearly", "every six months" */
    public function cycleLabel(): string
    {
        return mb_strtolower($this->cycle->label());
    }

    /** "On the first day of each period", "5 days after each period starts". */
    public function dueLabel(): string
    {
        return match ($this->dueDayOffset) {
            0 => 'On the first day of each period',
            1 => '1 day after each period starts',
            default => $this->dueDayOffset.' days after each period starts',
        };
    }

    public function firstDueLabel(): string
    {
        return DocumentDates::long($this->firstDue);
    }

    public function paidUntilLabel(): ?string
    {
        return $this->paidUntil !== null ? DocumentDates::long($this->paidUntil) : null;
    }

    /**
     * The clauses, numbered on the page in this order, as plain text.
     *
     * @return list<string>
     */
    public function clauses(): array
    {
        return ContractClauses::render($this->clauseTemplate, $this->placeholderValues());
    }

    /**
     * The values the agency's clause template may use (ContractClauses::PLACEHOLDERS).
     *
     * @return array<string, string>
     */
    public function placeholderValues(): array
    {
        return [
            'tenant_name' => $this->tenant->name,
            'tenant_phone' => $this->tenant->phone ?? '',
            'landlord' => $this->landlordName(),
            'agency_name' => $this->brand->name(),
            'property' => $this->property->building,
            'unit' => $this->property->unit ?? '',
            'address' => $this->property->address ?? '',
            'rent' => $this->rent->format(),
            'rent_in_words' => $this->rent->inWords(),
            'cycle' => $this->cycleLabel(),
            'deposit' => $this->deposit->format(),
            'start_date' => DocumentDates::long($this->startDate),
            'end_date' => DocumentDates::long($this->endDate),
            'contract_number' => $this->number,
        ];
    }

    /** The footer's "company · document" part. */
    public function footerLine(): string
    {
        return $this->brand->branding->companyName.' · Tenancy agreement '.$this->number;
    }
}
