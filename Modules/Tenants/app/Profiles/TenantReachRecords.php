<?php

declare(strict_types=1);

namespace Modules\Tenants\Profiles;

use App\Enums\InvitationKind;
use App\Models\Invitation;
use Illuminate\Database\Eloquent\Builder;
use Modules\Tenants\Contracts\TenantReach;
use Modules\Tenants\Models\TenantProfile;

/**
 * The Tenants module's answer to Contracts\TenantReach (P05-01): archived
 * tenants are never contacted; an imported one only once the agency
 * invited them (a `tenant` invitation, whatever became of it) or they hold
 * a portal account. Read through the organization scope.
 */
final readonly class TenantReachRecords implements TenantReach
{
    public function contactable(array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }

        return array_values(array_map(static fn (mixed $id): int => (int) $id, TenantProfile::query()
            ->whereKey(array_values(array_unique($tenantIds)))
            ->where(static function (Builder $query): void {
                $query->whereNull('import_batch_id')
                    ->orWhereNotNull('user_id')
                    ->orWhereIn('id', Invitation::query()->select('tenant_profile_id')
                        ->where('kind', InvitationKind::Tenant)
                        ->whereNotNull('tenant_profile_id'));
            })
            ->orderBy('id')
            ->pluck('id')
            ->all()));
    }
}
