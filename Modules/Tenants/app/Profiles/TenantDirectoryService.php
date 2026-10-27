<?php

declare(strict_types=1);

namespace Modules\Tenants\Profiles;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;
use Modules\Properties\Contracts\BuildingAccess;
use Modules\Tenants\Access\TenantNarrowing;
use Modules\Tenants\Contracts\TenantDirectory;
use Modules\Tenants\Contracts\TenantSummary;
use Modules\Tenants\Lists\TenantList;
use Modules\Tenants\Models\TenantProfile;

/** The Tenants module's answer to Contracts\TenantDirectory, through the organization scope. */
final readonly class TenantDirectoryService implements TenantDirectory
{
    public function __construct(private TenantNarrowing $narrowing, private BuildingAccess $access) {}

    public function find(int $tenantId, ?User $for = null): ?TenantSummary
    {
        $tenant = TenantProfile::query()->withTrashed()->find($tenantId);

        if (! $tenant instanceof TenantProfile || ($for !== null && ! Gate::forUser($for)->allows('view', $tenant))) {
            return null;
        }

        return self::summary($tenant);
    }

    public function findMany(array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }

        return TenantProfile::query()->withTrashed()->whereKey(array_values(array_unique($tenantIds)))->get()
            ->mapWithKeys(fn (TenantProfile $tenant): array => [$tenant->id => self::summary($tenant)])
            ->all();
    }

    public function search(string $search, int $limit = 1000): array
    {
        $search = trim($search);

        if ($search === '') {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $id): int => (int) $id,
            TenantList::matching(TenantProfile::query()->withTrashed(), $search)->orderBy('id')->limit($limit)->pluck('id')->all(),
        ));
    }

    public function activeOptions(User $for, int $limit = 1000): array
    {
        $options = [];

        foreach ($this->narrowing->tenants(TenantProfile::query(), $for)->orderBy('last_name')->orderBy('first_name')->orderBy('id')->limit($limit)->get() as $tenant) {
            $options[$tenant->id] = $tenant->fullName().' ('.$tenant->phone.')';
        }

        return $options;
    }

    public function lockActive(int $tenantId): ?TenantSummary
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Lock a tenant inside a database transaction.');
        }

        $tenant = TenantProfile::query()->whereKey($tenantId)->lockForUpdate()->first();

        return $tenant instanceof TenantProfile ? self::summary($tenant) : null;
    }

    public function narrowingAllows(User $member, int $tenantId): bool
    {
        $tenant = TenantProfile::query()->withTrashed()->find($tenantId);

        return $tenant instanceof TenantProfile && $this->narrowing->allows($member, $tenant);
    }

    public function narrowedIdsQuery(User $member): ?Builder
    {
        if ($this->access->limitedTo($member) === null) {
            return null;
        }

        return $this->narrowing->tenants(TenantProfile::query()->withTrashed(), $member)->select('id')->toBase();
    }

    private static function summary(TenantProfile $tenant): TenantSummary
    {
        return new TenantSummary(
            id: $tenant->id,
            organizationId: $tenant->organization_id,
            name: $tenant->fullName(),
            phone: $tenant->phone,
            email: $tenant->email,
            archived: $tenant->trashed(),
            hasPortalAccess: $tenant->user_id !== null,
            userId: $tenant->user_id,
        );
    }
}
