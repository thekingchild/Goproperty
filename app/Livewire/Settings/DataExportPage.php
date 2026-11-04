<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Models\DataRequest;
use App\Models\User;
use App\Privacy\DataRequestKind;
use App\Privacy\DataRequestRefused;
use App\Privacy\DataRequests;
use App\Tenancy\AllowedWhileSuspended;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Tenants\Contracts\TenantDirectory;
use Modules\Tenants\Contracts\TenantSummary;

/**
 * Settings → Data export, /o/{organization}/settings/data-export (P09-02;
 * NDPA 2023 ss. 34–36): the owner exports the organization's data, before
 * leaving or for its own records, or one tenant's data to answer that
 * tenant's request for a copy (the organization is the data controller).
 * The archive (JSON and the files) is made in the background and listed
 * here for 7 days, each download through a link that lives 15 minutes and
 * is audited.
 *
 * Owners only (`privacy.export`, OrganizationPolicy::exportData); kept while
 * the organization is suspended or cancelled (an export) and never during a
 * GoProperty support session (personal data never leaves with staff,
 * PLATFORM_CONSOLE.md § 6). Erasure is asked from GoProperty (the privacy
 * page names the data protection officer); it is not offered here.
 */
#[Layout('layouts::app')]
#[Title('Data export')]
final class DataExportPage extends Component
{
    #[Locked]
    public int $organizationId;

    public string $tenantSearch = '';

    public ?int $tenantId = null;

    public string $reason = '';

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
        $this->authorize('exportData', $context->get());
    }

    #[AllowedWhileSuspended]
    #[BlockedWhileImpersonating]
    public function exportOrganization(OrganizationContext $context, DataRequests $requests): void
    {
        $this->authorize('exportData', $context->get());
        $this->resetErrorBag();

        try {
            $requests->export($context->get(), null, 'The organization\'s owner exported its data from Settings → Data export.', $this->user());
        } catch (DataRequestRefused $refused) {
            $this->addError('organization', $refused->getMessage());

            return;
        }

        $this->notice = __('The export is being made. It appears below to download, usually within a few minutes.');
    }

    #[AllowedWhileSuspended]
    #[BlockedWhileImpersonating]
    public function exportTenant(OrganizationContext $context, DataRequests $requests): void
    {
        $this->authorize('exportData', $context->get());
        $this->validate([
            'tenantId' => ['required', 'integer'],
            'reason' => ['required', 'string', 'min:5', 'max:300'],
        ], [
            'tenantId.required' => __('Choose the tenant.'),
            'reason.required' => __('Say who asked and when, for example "Asked by email on 3 November".'),
        ]);

        try {
            $requests->export($context->get(), (int) $this->tenantId, $this->reason, $this->user());
        } catch (DataRequestRefused $refused) {
            $this->addError('tenantId', $refused->getMessage());

            return;
        }

        $this->reset('tenantSearch', 'tenantId', 'reason');
        $this->notice = __('The tenant\'s export is being made. It appears below to download, usually within a minute.');
    }

    public function chooseTenant(int $id): void
    {
        $this->tenantId = $id;
    }

    public function render(OrganizationContext $context, DataRequests $requests): View
    {
        $exports = DataRequest::query()
            ->where('organization_id', $this->organizationId)
            ->where('kind', DataRequestKind::Export)
            ->where('created_at', '>=', now()->subDays(DataRequests::FILE_DAYS + 1))
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return view('livewire.settings.data-export-page', [
            'exports' => $exports,
            'links' => $exports->filter(fn (DataRequest $export): bool => $export->isDownloadable())
                ->mapWithKeys(fn (DataRequest $export): array => [$export->id => $requests->downloadUrl($export, console: false)])
                ->all(),
            'matches' => $this->matches(),
            'chosen' => $this->tenantId !== null ? $this->tenantLabel($this->tenantId) : null,
            'days' => DataRequests::FILE_DAYS,
        ]);
    }

    /**
     * Tenants of this organization matching the search (name, phone or email), at most 8.
     *
     * @return array<int, string>
     */
    private function matches(): array
    {
        $search = trim($this->tenantSearch);

        if (mb_strlen($search) < 2) {
            return [];
        }

        $directory = app(TenantDirectory::class);

        return array_map(
            static fn (TenantSummary $tenant): string => $tenant->name.' · '.$tenant->phone.($tenant->archived ? ' (archived)' : ''),
            $directory->findMany($directory->search($search, 8)),
        );
    }

    private function tenantLabel(int $id): ?string
    {
        $tenant = app(TenantDirectory::class)->find($id);

        return $tenant === null ? null : $tenant->name.' · '.$tenant->phone;
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
