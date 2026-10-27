<?php

declare(strict_types=1);

namespace Modules\Tenants\Livewire;

use App\Exports\Livewire\WithListExports;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Tenants\Access\TenantNarrowing;
use Modules\Tenants\Enums\KycStatus;
use Modules\Tenants\Lists\TenantList;
use Modules\Tenants\Models\TenantProfile;
use Modules\Tenants\Profiles\TenantProfiles;

/**
 * Tenants, /o/{organization}/tenants: the organization's tenants
 * (DATABASE_SCHEMA.md § 1 `tenant_profiles`), 25 a page on the server,
 * with a debounced search over name, phone and email, filters for KYC
 * status and portal access, and an Active / Archived switch, all kept in
 * the URL. Members with `tenants.view` see the list; with `tenants.manage`
 * they add, archive and restore (soft deletes only). The rows come from
 * Lists\TenantList, which also exports them (P02-07, Export button; refused
 * to impersonating staff: contact details).
 *
 * Runs inside the `organization` middleware, replayed for Livewire updates:
 * every request has the organization's context and the component refuses
 * any other. Every action looks the tenant up through the organization
 * scope and the member's narrowing and checks TenantProfilePolicy (the
 * writer checks again), so a guessed id of another organization answers 404.
 */
#[Layout('layouts::app')]
#[Title('Tenants')]
final class TenantIndex extends Component
{
    use WithListExports, WithPagination;

    public const int PER_PAGE = 25;

    #[Locked]
    public int $organizationId;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** A KycStatus value, or empty for every status. */
    #[Url(except: '')]
    public string $kyc = '';

    /** "yes" (has portal access), "no", or empty for everyone. */
    #[Url(except: '')]
    public string $portal = '';

    #[Url(except: false)]
    public bool $archived = false;

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

    public function mount(OrganizationContext $context): void
    {
        $this->organizationId = $context->id();
        $this->authorize('viewAny', TenantProfile::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'kyc', 'portal', 'archived'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'kyc', 'portal');
        $this->resetPage();
    }

    public function archive(int $tenantId, TenantProfiles $tenants, TenantNarrowing $narrowing): void
    {
        $tenant = $narrowing->tenants(TenantProfile::query(), $this->user())->findOrFail($tenantId);
        $this->authorize('delete', $tenant);

        $tenants->archive($this->user(), $tenant);
        $this->notice = __(':name was archived. Find them under Archived to restore them.', ['name' => $tenant->fullName()]);
    }

    public function restore(int $tenantId, TenantProfiles $tenants, TenantNarrowing $narrowing): void
    {
        $tenant = $narrowing->tenants(TenantProfile::query()->onlyTrashed(), $this->user())->findOrFail($tenantId);
        $this->authorize('restore', $tenant);

        $tenants->restore($this->user(), $tenant);
        $this->notice = __(':name was restored.', ['name' => $tenant->fullName()]);
    }

    public function render(OrganizationContext $context, TenantList $list): View
    {
        $filters = $list->filters($this->listExports()['tenants']);
        $tenants = $list->query($this->user(), $filters)->paginate(self::PER_PAGE);

        return view('tenants::livewire.tenant-index', [
            'organization' => $context->get(),
            'tenants' => $tenants,
            'filtered' => array_diff_key($filters, ['archived' => true]) !== [],
            'canManage' => $this->user()->can('create', TenantProfile::class),
            'statuses' => KycStatus::cases(),
        ]);
    }

    /** The list on the page, with its filters (TenantList; the Export button). */
    protected function listExports(): array
    {
        return ['tenants' => [
            'search' => $this->search,
            'archived' => $this->archived,
            'kyc' => $this->kyc,
            'portal' => $this->portal,
        ]];
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
