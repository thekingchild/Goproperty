<?php

declare(strict_types=1);

namespace Modules\Tenants\Handover;

use App\Tenancy\Moves\BuildingMove;
use App\Tenancy\Moves\BuildingMoveFailed;
use App\Tenancy\Moves\MovesBuildingRecords;
use App\Tenancy\OrganizationContext;
use Modules\Leasing\Contracts\LiveLeases;
use Modules\Tenants\Models\TenantProfile;

/**
 * The Tenants module's part of a management handover (BILLING_PAYMENTS.md
 * § 7.4; App\Tenancy\Moves). A tenant has no building link, so the tenants
 * that go are those holding the moved buildings' live leases (Leasing's
 * LiveLeases). The tenant's record itself stays where it is: the bills,
 * receipts, payments and credit issued to it there are history and never
 * move. Where the buildings go, the tenant is the one with the same phone
 * number there (unique per organization; an archived one is restored),
 * else a copy of the record: name, contacts, next of kin, ID (still
 * encrypted) and KYC status, and the portal account (`user_id`), so the
 * tenant keeps their portal. Import provenance is not copied. Leasing's
 * part then points each lease at that tenant (BuildingMove::tenantIn()).
 */
final readonly class TenantsMover implements MovesBuildingRecords
{
    public const string LOG = 'tenants';

    public function __construct(
        private OrganizationContext $context,
        private LiveLeases $leases,
    ) {}

    public function order(): int
    {
        return 20;
    }

    public function move(BuildingMove $move): void
    {
        $ids = $this->leases->tenantIdsIn($move->propertyIds);
        $tenants = TenantProfile::query()->withTrashed()->whereKey($ids)->orderBy('id')->get();

        if ($tenants->count() !== count($ids)) {
            throw new BuildingMoveFailed('A tenant of these buildings could not be found.');
        }

        foreach ($tenants as $tenant) {
            $move->mapTenant($tenant->id, $this->context->run($move->to, fn (): int => $this->standIn($move, $tenant)));
        }
    }

    /** The tenant there with the same phone number, else a copy of this one. */
    private function standIn(BuildingMove $move, TenantProfile $tenant): int
    {
        $match = TenantProfile::query()->withTrashed()->where('phone', $tenant->phone)->first();

        if ($match !== null) {
            if ($match->trashed()) {
                $match->restore();
            }

            if ($match->user_id === null && $tenant->user_id !== null) {
                $match->forceFill(['user_id' => $tenant->user_id])->save();
            }

            $move->count('tenants_matched');

            return $match->id;
        }

        $copy = new TenantProfile;
        $copy->forceFill([
            'user_id' => $tenant->user_id,
            'first_name' => $tenant->first_name,
            'last_name' => $tenant->last_name,
            'email' => $tenant->email,
            'phone' => $tenant->phone,
            'address' => $tenant->address,
            'occupation' => $tenant->occupation,
            'emergency_contact' => $tenant->emergency_contact,
            'kyc_status' => $tenant->kyc_status,
            'id_type' => $tenant->id_type,
            'id_value' => $tenant->id_value,
        ])->save();

        activity(self::LOG)
            ->event('tenant.brought_over')
            ->performedOn($copy)
            ->withProperties([
                'handover_id' => $move->handoverId,
                'from_organization_id' => $move->from->id,
                'from_tenant_profile_id' => $tenant->id,
                'direction' => $move->direction->value,
            ])
            ->log("Tenant brought over from {$move->from->name} with their building");

        $move->count('tenants_copied');

        return $copy->id;
    }
}
