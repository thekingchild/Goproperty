<?php

declare(strict_types=1);

namespace Modules\Tenants\Contracts;

/**
 * A tenant as the owner of their building sees them (Contracts\OwnerTenants,
 * P03-16): plain values, no model. `phone` is E.164. `kycStatus` is a
 * `tenant_profiles.kyc_status` value with its label and Flux badge colour;
 * `idType` an `id_type` value with its label. `idNumberMasked` is the ID
 * number's last four characters behind dots ("···7890", or "···" for a
 * number of four characters or fewer): the full number is never here, so
 * it can never reach an owner's page or a Livewire payload. Null when no
 * number is on file.
 */
final readonly class TenantDetails
{
    public function __construct(
        public int $id,
        public int $organizationId,
        public string $name,
        public string $phone,
        public ?string $email,
        public ?string $address,
        public ?string $occupation,
        public ?string $emergencyName,
        public ?string $emergencyPhone,
        public ?string $emergencyRelationship,
        public string $kycStatus,
        public string $kycStatusLabel,
        public string $kycStatusColor,
        public string $idType,
        public string $idTypeLabel,
        public ?string $idNumberMasked,
        public bool $archived,
    ) {}

    public function hasEmergencyContact(): bool
    {
        return $this->emergencyName !== null || $this->emergencyPhone !== null || $this->emergencyRelationship !== null;
    }
}
