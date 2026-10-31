<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * The documents a Transaction Tier application can carry
 * (ONBOARDING_COMPLIANCE.md § 5.3, DATABASE_SCHEMA.md § 9). Which ones a tier
 * requires is set per tier version in Compliance → Tier settings
 * (`transaction_tiers`, P06-08; App\Compliance\RequiredDocuments).
 */
enum ComplianceDocumentType: string
{
    use HasValues;

    case OwnerId = 'owner_id';
    case CacCertificate = 'cac_certificate';
    case CacStatusReport = 'cac_status_report';
    case ScumlCertificate = 'scuml_certificate';
    case ProofOfAddress = 'proof_of_address';
    case DirectorId = 'director_id';
    case BeneficialOwners = 'beneficial_owners';
    case AmlPolicy = 'aml_policy';
    case StateRegulatorRegistration = 'state_regulator_registration';
    case ProfessionalMembership = 'professional_membership';

    public function label(): string
    {
        return match ($this) {
            self::OwnerId => 'Owner\'s government ID',
            self::CacCertificate => 'CAC certificate',
            self::CacStatusReport => 'CAC status report',
            self::ScumlCertificate => 'SCUML registration certificate',
            self::ProofOfAddress => 'Proof of business address',
            self::DirectorId => 'Director\'s ID',
            self::BeneficialOwners => 'Beneficial owners',
            self::AmlPolicy => 'AML policy statement',
            self::StateRegulatorRegistration => 'State real-estate regulator registration',
            self::ProfessionalMembership => 'Professional body membership',
        };
    }

    public function help(): string
    {
        return match ($this) {
            self::OwnerId => 'NIN slip, international passport, driver\'s licence or voter\'s card of the organization\'s owner.',
            self::CacCertificate => 'Certificate of incorporation or business name registration.',
            self::CacStatusReport => 'A recent CAC status report for the business.',
            self::ScumlCertificate => 'Your SCUML registration certificate (needs a CAC-registered business).',
            self::ProofOfAddress => 'A utility bill for the business address, dated within the last 3 months.',
            self::DirectorId => 'A government ID for each director. Upload one per director.',
            self::BeneficialOwners => 'The people with significant control (for example the CAC persons-with-significant-control list), with their shareholding.',
            self::AmlPolicy => 'A short statement of your anti-money-laundering policy.',
            self::StateRegulatorRegistration => 'For example LASRERA registration in Lagos, where your state requires one.',
            self::ProfessionalMembership => 'ESVARBON or NIESV membership. Optional; shown as a trust badge.',
        };
    }

    /** IDs and registrations carry a number (stored encrypted). */
    public function needsDocumentNumber(): bool
    {
        return in_array($this, [self::OwnerId, self::DirectorId, self::CacCertificate, self::ScumlCertificate], true);
    }

    /** Whose document it is, for the documents about a person. */
    public function needsHolderName(): bool
    {
        return $this === self::OwnerId || $this === self::DirectorId;
    }

    /** The document is only good while recent, so its date is required. */
    public function needsIssueDate(): bool
    {
        return $this === self::ProofOfAddress || $this === self::CacStatusReport;
    }

    /** Months the document may be old when the application is submitted (null: no limit). */
    public function maxAgeMonths(): ?int
    {
        return $this === self::ProofOfAddress ? 3 : null;
    }

    /** Several of this type may be uploaded (one per director, several pages). */
    public function allowsSeveral(): bool
    {
        return $this === self::DirectorId || $this === self::BeneficialOwners;
    }
}
