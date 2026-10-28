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
 * move. Where the buildings go, the tenant is (BILLING_PAYMENTS.md § 7.4,
 * product owner 2026-10-28, P04-04), in this order:
 *
 * 1. their own record there, as an earlier handover between the two
 *    organizations recorded it (BuildingMove::knownTenantIn(): the
 *    landlord's original when tenants come back at the end of a management
 *    or with a one-way return), so a tenant whose phone number changed
 *    meanwhile lands on it and no third record is made;
 * 2. else the one with the same phone number there (unique per
 *    organization);
 * 3. else a copy of the record: name, contacts, next of kin, ID (still
 *    encrypted) and KYC status, and the portal account (`user_id`), so the
 *    tenant keeps their portal. Import provenance is not copied.
 *
 * A matched record that was archived (for example by itself, once nothing
 * was owed after an earlier move) is restored, and gets the portal account
 * when it has none. Its details are left as they are (the organization
 * edits them). Leasing's part then points each lease at that tenant
 * (BuildingMove::tenantIn()).
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

    /** The tenant's own record there (from an earlier handover), else the one with the same phone number, else a copy of this one. */
    private function standIn(BuildingMove $move, TenantProfile $tenant): int
    {
        $known = $move->knownTenantIn($tenant->id);
        $original = $known !== null ? TenantProfile::query()->withTrashed()->find($known) : null;

        if ($original !== null) {
            $move->count('tenants_back_on_their_record');

            return $this->take($original, $tenant);
        }

        $match = TenantProfile::query()->withTrashed()->where('phone', $tenant->phone)->first();

        if ($match !== null) {
            $move->count('tenants_matched');

            return $this->take($match, $tenant);
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

    /** Uses an existing record there for the tenant: restored when archived, with their portal account when it has none. */
    private function take(TenantProfile $record, TenantProfile $tenant): int
    {
        if ($record->trashed()) {
            $record->restore();
        }

        if ($record->user_id === null && $tenant->user_id !== null) {
            $record->forceFill(['user_id' => $tenant->user_id])->save();
        }

        return $record->id;
    }
}
