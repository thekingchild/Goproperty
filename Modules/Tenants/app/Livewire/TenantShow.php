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
use Modules\Tenants\Models\TenantProfile;
use Modules\Tenants\Profiles\TenantProfiles;

/**
 * A tenant's record, /o/{organization}/tenants/{tenant}: contact details,
 * next of kin, identity (ID masked; the number is never sent to the
 * browser) and KYC status, portal access, and a place for the tenant's
 * leases and bills (filled by P03-03a and P03-04b). Members with
 * `tenants.view` open it, an archived tenant too (read-only, with Restore
 * for `tenants.manage`); `tenants.manage` edits and archives
 * (TenantProfilePolicy, checked again by Profiles\TenantProfiles).
 *
 * The tenant id is locked and the tenant loaded through the organization
 * scope on every request, so another organization's tenant answers 404.
 */
#[Layout('layouts::app')]
#[Title('Tenant')]
final class TenantShow extends Component
{
    #[Locked]
    public int $organizationId;

    #[Locked]
    public int $tenantId;

    #[Locked]
    public ?string $notice = null;

    public function boot(OrganizationContext $context): void
    {
        abort_unless($context->has(), 404);
    }

    public function hydrate(OrganizationContext $context): void
    {
        abort_unless($context->id() === $this->organizationId, 404);
    }

    public function mount(OrganizationContext $context, TenantProfile $tenant): void
    {
        $this->organizationId = $context->id();
        $this->authorize('view', $tenant);
        $this->tenantId = $tenant->id;
    }

    public function archive(TenantProfiles $tenants): void
    {
        $tenant = $this->tenant();
        $this->authorize('delete', $tenant);

        $tenants->archive($this->user(), $tenant);
        $this->notice = __(':name was archived. The record is kept, and you can restore it at any time.', ['name' => $tenant->fullName()]);
    }

    public function restore(TenantProfiles $tenants): void
    {
        $tenant = $this->tenant();
        $this->authorize('restore', $tenant);

        $tenants->restore($this->user(), $tenant);
        $this->notice = __(':name was restored.', ['name' => $tenant->fullName()]);
    }

    public function render(OrganizationContext $context): View
    {
        $tenant = $this->tenant();
        // Checked on every render, not only when the page opened.
        $this->authorize('view', $tenant);
        $user = $this->user();

        return view('tenants::livewire.tenant-show', [
            'organization' => $context->get(),
            'tenant' => $tenant,
            'maskedId' => $tenant->maskedId(),
            'canEdit' => $user->can('update', $tenant),
            'canArchive' => $user->can('delete', $tenant),
            'canRestore' => $user->can('restore', $tenant),
            'flash' => session('tenant-status'),
        ]);
    }

    private function tenant(): TenantProfile
    {
        return TenantProfile::query()->withTrashed()->findOrFail($this->tenantId);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
