<?php

declare(strict_types=1);

namespace Modules\Tenants\Lists;

use App\Exports\ExportColumn;
use App\Exports\ListFilters;
use App\Exports\QueryListExport;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Modules\Tenants\Access\TenantNarrowing;
use Modules\Tenants\Enums\KycStatus;
use Modules\Tenants\Models\TenantProfile;

/**
 * The tenants list (/o/{slug}/tenants, Livewire\TenantIndex) and its
 * export (P02-07): the tenants the member sees (Access\TenantNarrowing),
 * active or archived, filtered by KYC status and portal access, with the
 * search over name, email and phone, by last name then first name (the
 * `(organization_id, deleted_at, last_name, first_name)` index).
 *
 * The export's headers are the tenants import's (DATA_IMPORT.md § 1 row 4),
 * so a file exported here imports again as updates. ID numbers are
 * encrypted and never exported: the column shows the masked number the
 * page shows ("NIN ···2109"). It holds people's contact details, so
 * TenantProfilePolicy::export refuses it to GoProperty staff while
 * impersonating.
 *
 * @extends QueryListExport<TenantProfile>
 */
final class TenantList extends QueryListExport
{
    /** The portal filter's values. */
    public const array PORTAL = ['yes', 'no'];

    public function __construct(private readonly TenantNarrowing $narrowing) {}

    public function key(): string
    {
        return 'tenants';
    }

    public function title(): string
    {
        return 'Tenants';
    }

    public function filters(array $input): array
    {
        return ListFilters::set([
            'search' => ListFilters::search($input['search'] ?? null),
            'archived' => ListFilters::flag($input['archived'] ?? false),
            'kyc' => ListFilters::option($input['kyc'] ?? null, KycStatus::values()),
            'portal' => ListFilters::option($input['portal'] ?? null, self::PORTAL),
        ]);
    }

    public function authorize(User $user, array $filters): void
    {
        Gate::forUser($user)->authorize('export', TenantProfile::class);
    }

    public function query(User $user, array $filters): Builder
    {
        $search = is_string($filters['search'] ?? null) ? $filters['search'] : null;
        $kyc = is_string($filters['kyc'] ?? null) ? $filters['kyc'] : null;
        $portal = $filters['portal'] ?? null;

        return $this->narrowing->tenants(TenantProfile::query(), $user)
            ->when(($filters['archived'] ?? false) === true, fn (Builder $query) => $query->onlyTrashed())
            ->when($kyc !== null, fn (Builder $query) => $query->where('kyc_status', $kyc))
            ->when($portal === 'yes', fn (Builder $query) => $query->whereNotNull('user_id'))
            ->when($portal === 'no', fn (Builder $query) => $query->whereNull('user_id'))
            ->when($search !== null, function (Builder $query) use ($search): void {
                $like = ListFilters::like((string) $search);
                $digits = preg_replace('/\D/', '', (string) $search) ?? '';

                $query->where(function (Builder $query) use ($like, $digits): void {
                    $query->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        // "Adaeze Oka" finds Adaeze Okafor (CONCAT: MySQL and MariaDB).
                        ->orWhereRaw("concat(first_name, ' ', last_name) like ?", [$like])
                        ->orWhere('email', 'like', $like);

                    // Phones are stored as +234…; "0803 123" finds +234803123….
                    if (strlen($digits) >= 4) {
                        $query->orWhere('phone', 'like', '%'.(str_starts_with($digits, '0') ? substr($digits, 1) : $digits).'%');
                    }
                });
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id');
    }

    public function columns(User $user): array
    {
        return [
            ExportColumn::make('Your ID', fn (TenantProfile $tenant) => $tenant->external_ref),
            ExportColumn::make('First name', fn (TenantProfile $tenant) => $tenant->first_name),
            ExportColumn::make('Last name', fn (TenantProfile $tenant) => $tenant->last_name),
            ExportColumn::make('Phone', fn (TenantProfile $tenant) => $tenant->phone),
            ExportColumn::make('Email', fn (TenantProfile $tenant) => $tenant->email),
            ExportColumn::make('Address', fn (TenantProfile $tenant) => $tenant->address),
            ExportColumn::make('Occupation', fn (TenantProfile $tenant) => $tenant->occupation),
            ExportColumn::make('Next of kin', fn (TenantProfile $tenant) => $tenant->emergency_contact['name'] ?? null),
            ExportColumn::make('Next of kin phone', fn (TenantProfile $tenant) => $tenant->emergency_contact['phone'] ?? null),
            ExportColumn::make('Next of kin relationship', fn (TenantProfile $tenant) => $tenant->emergency_contact['relationship'] ?? null),
            // Encrypted at rest: never exported in full (AGENT.md § 5).
            ExportColumn::make('ID (masked)', fn (TenantProfile $tenant) => $tenant->maskedId()),
            ExportColumn::make('KYC status', fn (TenantProfile $tenant) => $tenant->kyc_status),
            ExportColumn::make('Portal access', fn (TenantProfile $tenant): bool => $tenant->user_id !== null),
            ExportColumn::make('Added on', fn (TenantProfile $tenant) => $tenant->created_at),
            ExportColumn::make('Archived on', fn (TenantProfile $tenant) => $tenant->deleted_at),
        ];
    }
}
