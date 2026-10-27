<?php

declare(strict_types=1);

namespace Modules\Tenants\Portal;

use App\Auth\PhoneVerification;
use App\Enums\PhoneVerificationMethod;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Tenants\Models\TenantProfile;

/**
 * "Phone number confirmed in person" (ONBOARDING_COMPLIANCE.md § 2: the
 * agency met the tenant, e.g. at signing or handover, and saw the number
 * work). Marks the tenant's account's number verified (`agency_confirmed`,
 * recording who), which later allows SMS reminders to it
 * (ARCHITECTURE.md § 6.7). Only the number on the tenant's record, only
 * when the account has that very number, and only once; audited under log
 * `tenants`. Needs TenantProfilePolicy::managePortalAccess.
 */
final readonly class PhoneConfirmations
{
    public function __construct(
        private PhoneVerification $phones,
        private PortalAccessAudit $audit,
    ) {}

    /** @throws PortalAccessRefused */
    public function confirm(User $actor, TenantProfile $tenant): User
    {
        Gate::forUser($actor)->authorize('managePortalAccess', $tenant);

        return DB::transaction(function () use ($actor, $tenant): User {
            $locked = TenantProfile::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $account = $locked->user_id !== null ? User::query()->lockForUpdate()->find($locked->user_id) : null;

            if ($account === null) {
                throw new PortalAccessRefused('This tenant has no portal account yet. Confirm the number once they have accepted their invitation.');
            }

            if ($account->phone !== $locked->phone) {
                throw new PortalAccessRefused('The tenant\'s account does not use the phone number on this record, so it cannot be confirmed here.');
            }

            if (! $this->phones->confirmedInPerson($account, $locked->phone, $actor)) {
                throw new PortalAccessRefused('This phone number is already verified.');
            }

            $this->audit->record('tenant.phone_confirmed', $locked, $actor, 'Confirmed the phone number in person', [
                'user_id' => $account->id,
                'method' => PhoneVerificationMethod::AgencyConfirmed->value,
            ]);

            return $account;
        });
    }
}
