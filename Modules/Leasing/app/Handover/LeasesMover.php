<?php

declare(strict_types=1);

namespace Modules\Leasing\Handover;

use App\Tenancy\Moves\BuildingMove;
use App\Tenancy\Moves\BuildingMoveFailed;
use App\Tenancy\Moves\MovesBuildingRecords;
use App\Tenancy\OrganizationContext;
use Modules\Leasing\Contracts\LiveLeases;
use Modules\Leasing\Enums\LeaseStatus;
use Modules\Leasing\Models\Lease;
use Modules\Leasing\Models\LeaseDepositEntry;

/**
 * The Leasing module's part of a management handover (BILLING_PAYMENTS.md
 * § 7.4; App\Tenancy\Moves): the live leases of the moved buildings
 * (draft, reserved and active: the ones still billing or about to) move
 * with their deposit ledger (the deposit is a liability the receiving
 * organization now holds) and their billing state (`next_billing_date`),
 * so the receiving organization's next bill run bills the next period with
 * its own numbers and branding. Each lease points to the tenant that
 * stands for its tenant there (the Tenants module's part ran first).
 *
 * History stays: ended leases (expired, terminated, renewed), issued
 * contracts (`lease_contracts` and their PDFs) and their numbers. A
 * contract number or import reference already used there is cleared on the
 * moved lease (the issued contract keeps its number); the import batch is
 * cleared.
 *
 * Also answers LiveLeases.
 */
final readonly class LeasesMover implements LiveLeases, MovesBuildingRecords
{
    public function __construct(private OrganizationContext $context) {}

    public function order(): int
    {
        return 30;
    }

    public function tenantIdsIn(array $propertyIds): array
    {
        if ($propertyIds === []) {
            return [];
        }

        return array_values(Lease::query()
            ->whereIn('property_id', $propertyIds)
            ->whereIn('status', [LeaseStatus::Draft, LeaseStatus::Active])
            ->distinct()
            ->orderBy('tenant_profile_id')
            ->pluck('tenant_profile_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all());
    }

    public function move(BuildingMove $move): void
    {
        $leases = Lease::query()
            ->whereIn('property_id', $move->propertyIds)
            ->whereIn('status', [LeaseStatus::Draft, LeaseStatus::Active])
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'tenant_profile_id', 'contract_number', 'external_ref']);

        if ($leases->isEmpty()) {
            return;
        }

        $numbers = $leases->pluck('contract_number')->filter()->values()->all();
        $refs = $leases->pluck('external_ref')->filter()->values()->all();
        [$takenNumbers, $takenRefs] = $this->context->run($move->to, static fn (): array => [
            $numbers === [] ? [] : Lease::query()->withTrashed()->whereIn('contract_number', $numbers)->pluck('contract_number')->all(),
            $refs === [] ? [] : Lease::query()->withTrashed()->whereIn('external_ref', $refs)->pluck('external_ref')->all(),
        ]);

        foreach ($leases as $lease) {
            $tenant = $move->tenantIn($lease->tenant_profile_id)
                ?? throw new BuildingMoveFailed("The tenant of lease {$lease->id} was not brought over.");
            $numberTaken = $lease->contract_number !== null && in_array($lease->contract_number, $takenNumbers, true);
            $refTaken = $lease->external_ref !== null && in_array($lease->external_ref, $takenRefs, true);

            $move->reassign(Lease::class, [$lease->id], [
                'tenant_profile_id' => $tenant,
                'contract_number' => $numberTaken ? null : $lease->contract_number,
                'external_ref' => $refTaken ? null : $lease->external_ref,
                'import_batch_id' => null,
            ]);
            $move->count('contract_numbers_cleared', $numberTaken ? 1 : 0);
            $move->count('external_refs_cleared', $refTaken ? 1 : 0);
        }

        $deposits = LeaseDepositEntry::query()->whereIn('lease_id', $leases->modelKeys())->orderBy('id')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $move->reassign(LeaseDepositEntry::class, $deposits);
    }
}
