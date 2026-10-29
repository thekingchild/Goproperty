<?php

declare(strict_types=1);

namespace Modules\Tenants\Profiles;

use Modules\Tenants\Contracts\OwnerTenants;
use Modules\Tenants\Contracts\TenantDetails;
use Modules\Tenants\Enums\KycStatus;
use Modules\Tenants\Enums\TenantIdType;
use Modules\Tenants\Models\TenantProfile;

/**
 * The Tenants module's answer to Contracts\OwnerTenants (the owner portal,
 * P03-16), through the organization scope. The ID number is decrypted here
 * only to cut it to its last four characters. Reads only.
 */
final readonly class OwnerTenantRecords implements OwnerTenants
{
    public function details(array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }

        $found = [];

        foreach (array_chunk(array_values(array_unique($tenantIds)), 1000) as $chunk) {
            foreach (TenantProfile::query()->withTrashed()->whereKey($chunk)->get() as $tenant) {
                $found[$tenant->id] = self::of($tenant);
            }
        }

        return $found;
    }

    /** "···7890": the last four characters only; a number that short is not shown at all. */
    public static function mask(?string $number): ?string
    {
        if ($number === null || trim($number) === '') {
            return null;
        }

        $number = trim($number);

        return mb_strlen($number) > 4 ? '···'.mb_substr($number, -4) : '···';
    }

    private static function of(TenantProfile $tenant): TenantDetails
    {
        $kin = is_array($tenant->emergency_contact) ? $tenant->emergency_contact : [];

        return new TenantDetails(
            id: $tenant->id,
            organizationId: $tenant->organization_id,
            name: $tenant->fullName(),
            phone: $tenant->phone,
            email: $tenant->email,
            address: self::text($tenant->address),
            occupation: self::text($tenant->occupation),
            emergencyName: self::text($kin['name'] ?? null),
            emergencyPhone: self::text($kin['phone'] ?? null),
            emergencyRelationship: self::text($kin['relationship'] ?? null),
            kycStatus: $tenant->kyc_status->value,
            kycStatusLabel: $tenant->kyc_status->label(),
            kycStatusColor: match ($tenant->kyc_status) {
                KycStatus::Verified => 'green',
                KycStatus::Pending => 'amber',
                KycStatus::Unverified => 'zinc',
            },
            idType: $tenant->id_type->value,
            idTypeLabel: $tenant->id_type->label(),
            idNumberMasked: $tenant->id_type === TenantIdType::None ? null : self::mask($tenant->id_value),
            archived: $tenant->trashed(),
        );
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
