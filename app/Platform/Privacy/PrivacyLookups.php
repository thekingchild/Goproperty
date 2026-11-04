<?php

declare(strict_types=1);

namespace App\Platform\Privacy;

use Illuminate\Support\Facades\DB;

/**
 * Finding the organization and tenant a data protection request is about
 * (P09-02; Console → Compliance → Data requests). A console read across
 * organizations (ARCHITECTURE.md § 4: only the Platform namespace may),
 * read-only, at most 20 results, for staff with `privacy.requests`.
 */
final class PrivacyLookups
{
    /** @return array<int, string> organization id => "Name (slug) · #id" */
    public function organizations(string $search): array
    {
        $search = trim($search);

        return DB::table('organizations')
            ->when($search !== '', fn ($query) => $query->where(fn ($where) => $where->where('name', 'like', '%'.$search.'%')->orWhere('slug', 'like', '%'.$search.'%')->orWhere('id', is_numeric($search) ? (int) $search : 0)))
            ->orderBy('name')->limit(20)->get(['id', 'name', 'slug', 'status'])
            ->mapWithKeys(fn (object $row): array => [(int) $row->id => "{$row->name} ({$row->slug}) · #{$row->id}, {$row->status}"])
            ->all();
    }

    public function organizationLabel(mixed $id): ?string
    {
        $row = is_numeric($id) ? DB::table('organizations')->where('id', (int) $id)->first(['id', 'name', 'slug']) : null;

        return $row === null ? null : "{$row->name} ({$row->slug}) · #{$row->id}";
    }

    /** @return array<int, string> tenant id => "First Last · phone · #id" */
    public function tenants(mixed $organizationId, string $search): array
    {
        if (! is_numeric($organizationId)) {
            return [];
        }

        $search = trim($search);

        return DB::table('tenant_profiles')->where('organization_id', (int) $organizationId)
            ->when($search !== '', fn ($query) => $query->where(fn ($where) => $where
                ->where(DB::raw("CONCAT(first_name, ' ', last_name)"), 'like', '%'.$search.'%')
                ->orWhere('phone', 'like', '%'.$search.'%')
                ->orWhere('email', 'like', '%'.$search.'%')
                ->orWhere('id', is_numeric($search) ? (int) $search : 0)))
            ->orderBy('last_name')->orderBy('first_name')->limit(20)->get(['id', 'first_name', 'last_name', 'phone'])
            ->mapWithKeys(fn (object $row): array => [(int) $row->id => "{$row->first_name} {$row->last_name} · {$row->phone} · #{$row->id}"])
            ->all();
    }

    public function tenantLabel(mixed $id): ?string
    {
        $row = is_numeric($id) ? DB::table('tenant_profiles')->where('id', (int) $id)->first(['id', 'first_name', 'last_name']) : null;

        return $row === null ? null : "{$row->first_name} {$row->last_name} · #{$row->id}";
    }
}
