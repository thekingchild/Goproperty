<?php

declare(strict_types=1);

namespace Modules\Tenants\Livewire;

use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Tenants\Access\TenantNarrowing;
use Modules\Tenants\Enums\KycStatus;
use Modules\Tenants\Enums\TenantIdType;
use Modules\Tenants\Models\TenantProfile;
use Modules\Tenants\Profiles\ArchivedTenantMatch;
use Modules\Tenants\Profiles\TenantProfiles;

/**
 * Add a tenant (/o/{organization}/tenants/create) or edit one
 * (/o/{organization}/tenants/{tenant}/edit): name, phone (how GoProperty
 * recognises a tenant, unique in the organization), email, address,
 * occupation, next of kin, ID and KYC status (DATABASE_SCHEMA.md § 1).
 * Members with `tenants.manage` open it (TenantProfilePolicy, checked again
 * by Profiles\TenantProfiles); an archived tenant is restored before it is
 * edited (its edit page answers 404).
 *
 * A phone number of an archived tenant is refused when adding (P02
 * decision 3) and the page offers to restore that tenant instead
 * (restoreArchivedMatch(): the id is kept server-side, locked, and the
 * tenant is looked up among the archived tenants of this organization).
 *
 * The ID number on file is never sent to the browser: the page shows it
 * masked, and an empty field keeps it. The tenant id is locked and loaded
 * through the organization scope on every save, so another organization's
 * tenant answers 404.
 */
#[Layout('layouts::app')]
#[Title('Tenant')]
final class TenantForm extends Component
{
    #[Locked]
    public int $organizationId;

    #[Locked]
    public ?int $tenantId = null;

    public string $firstName = '';

    public string $lastName = '';

    public string $phone = '';

    public string $email = '';

    public string $address = '';

    public string $occupation = '';

    public string $kinName = '';

    public string $kinPhone = '';

    public string $kinRelationship = '';

    public string $idType = 'none';

    /** A new ID number; empty keeps the one on file. */
    public string $idValue = '';

    public string $kycStatus = 'unverified';

    /** The archived tenant whose phone number was typed, to restore instead (set by the server only). */
    #[Locked]
    public ?int $archivedMatchId = null;

    public function boot(OrganizationContext $context): void
    {
        abort_unless($context->has(), 404);
    }

    public function hydrate(OrganizationContext $context): void
    {
        abort_unless($context->id() === $this->organizationId, 404);
    }

    public function mount(OrganizationContext $context, ?TenantProfile $tenant = null): void
    {
        $this->organizationId = $context->id();

        if ($tenant === null) {
            $this->authorize('create', TenantProfile::class);

            return;
        }

        $this->authorize('update', $tenant);
        $this->tenantId = $tenant->id;
        $this->fillFrom($tenant);
    }

    public function save(TenantProfiles $tenants): void
    {
        $input = [
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'occupation' => $this->occupation,
            'kinName' => $this->kinName,
            'kinPhone' => $this->kinPhone,
            'kinRelationship' => $this->kinRelationship,
            'idType' => $this->idType,
            'idValue' => $this->idValue,
            'kycStatus' => $this->kycStatus,
        ];
        $this->archivedMatchId = null;

        if ($this->tenantId === null) {
            $this->authorize('create', TenantProfile::class);

            try {
                $tenant = $tenants->create($this->user(), $input);
            } catch (ArchivedTenantMatch $match) {
                $this->archivedMatchId = $match->tenantId;

                throw $match;
            }

            session()->flash('tenant-status', __(':name was added.', ['name' => $tenant->fullName()]));
            $this->redirectRoute('workspace.tenants.show', ['organization' => $this->organizationSlug(), 'tenant' => $tenant->id], navigate: true);

            return;
        }

        $tenant = $this->tenant();
        $this->authorize('update', $tenant);
        $tenant = $tenants->update($this->user(), $tenant, $input);

        session()->flash('tenant-status', __('Saved.'));
        $this->redirectRoute('workspace.tenants.show', ['organization' => $this->organizationSlug(), 'tenant' => $tenant->id], navigate: true);
    }

    /**
     * Restore the archived tenant whose number was typed, instead of adding
     * them again; their details on file come back as they were.
     */
    public function restoreArchivedMatch(TenantProfiles $tenants, TenantNarrowing $narrowing): void
    {
        abort_if($this->tenantId !== null || $this->archivedMatchId === null, 404);

        $tenant = $narrowing->tenants(TenantProfile::query()->onlyTrashed(), $this->user())->findOrFail($this->archivedMatchId);
        $this->authorize('restore', $tenant);

        $tenants->restore($this->user(), $tenant);

        session()->flash('tenant-status', __(':name was restored with the details on file. Check them, and edit them if anything has changed.', ['name' => $tenant->fullName()]));
        $this->redirectRoute('workspace.tenants.show', ['organization' => $this->organizationSlug(), 'tenant' => $tenant->id], navigate: true);
    }

    public function render(OrganizationContext $context): View
    {
        $tenant = $this->tenantId !== null ? $this->tenant() : null;

        if ($tenant !== null) {
            // Checked on every render: archived (or out of reach) since the page opened.
            $this->authorize('update', $tenant);
        }

        $match = $this->archivedMatchId !== null ? TenantProfile::query()->onlyTrashed()->find($this->archivedMatchId) : null;

        return view('tenants::livewire.tenant-form', [
            'organization' => $context->get(),
            'tenant' => $tenant,
            'maskedId' => $tenant?->maskedId(),
            'storedIdType' => $tenant?->id_type,
            'idTypes' => TenantIdType::cases(),
            'statuses' => KycStatus::cases(),
            'archivedMatch' => $match,
            'canRestoreMatch' => $match !== null && $this->user()->can('restore', $match),
        ]);
    }

    private function tenant(): TenantProfile
    {
        return TenantProfile::query()->findOrFail($this->tenantId);
    }

    private function fillFrom(TenantProfile $tenant): void
    {
        $this->firstName = $tenant->first_name;
        $this->lastName = $tenant->last_name;
        $this->phone = $tenant->phone;
        $this->email = $tenant->email ?? '';
        $this->address = $tenant->address ?? '';
        $this->occupation = $tenant->occupation ?? '';
        $this->kinName = $tenant->emergency_contact['name'] ?? '';
        $this->kinPhone = $tenant->emergency_contact['phone'] ?? '';
        $this->kinRelationship = $tenant->emergency_contact['relationship'] ?? '';
        $this->idType = $tenant->id_type->value;
        $this->idValue = '';
        $this->kycStatus = $tenant->kyc_status->value;
    }

    private function organizationSlug(): string
    {
        return app(OrganizationContext::class)->get()->slug;
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
