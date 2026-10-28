<?php

declare(strict_types=1);

namespace Modules\Tenants\Import;

use App\Support\PhoneNumber;
use Modules\Import\Contracts\ImportRun;
use Modules\Tenants\Contracts\ImportedTenants;
use Modules\Tenants\Contracts\TenantDirectory;
use Modules\Tenants\Contracts\TenantReference;
use Modules\Tenants\Models\TenantProfile;

/**
 * Finds the tenant a Leases row names (P03-13a) the way the Tenants sheet
 * matches tenants: by Your ID (`external_ref`), then by phone number
 * (E.164). An archived tenant counts only when the same file's Tenants
 * sheet brings them back; a tenant the file adds is known from that
 * sheet's dry-run memory (`tenants`: the E.164 phone, and `ref:` + ID).
 */
final readonly class ImportedTenantLookup implements ImportedTenants
{
    public function __construct(private TenantDirectory $tenants) {}

    public function resolve(string $reference, ImportRun $run): TenantReference
    {
        $reference = trim((string) preg_replace('/\s+/u', ' ', $reference));

        if ($reference === '') {
            return TenantReference::refused(__('Enter the tenant\'s phone number or Your ID from the Tenants sheet.'));
        }

        $phone = PhoneNumber::normalize($reference);
        $record = TenantProfile::query()->withTrashed()->where('external_ref', $reference)->first()
            ?? ($phone !== null ? TenantProfile::query()->withTrashed()->where('phone', $phone)->first() : null);
        $inFile = $run->knows(TenantsImportTarget::KEY, 'ref:'.$reference)
            || ($phone !== null && $run->knows(TenantsImportTarget::KEY, $phone));

        if ($record !== null && ! $record->trashed()) {
            $summary = $this->tenants->find($record->id);

            if ($summary !== null) {
                return new TenantReference($summary, false, 'id:'.$record->id, $summary->name, null);
            }
        }

        if ($inFile) {
            return new TenantReference(null, true, $record !== null ? 'id:'.$record->id : ($phone !== null ? 'phone:'.$phone : 'ref:'.mb_strtolower($reference)), $record?->fullName() ?? $reference, null);
        }

        if ($record !== null) {
            return TenantReference::refused(__(':name is archived. Restore them on their page, or add them to the Tenants sheet to bring them back.', ['name' => $record->fullName()]));
        }

        return TenantReference::refused(__('No tenant has the phone number or ID ":tenant", here or in the Tenants sheet. Add them to the Tenants sheet first.', ['tenant' => mb_substr($reference, 0, 60)]));
    }
}
