<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Livewire;

use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Tenants\Contracts\TenantDirectory;
use Modules\Tenants\Contracts\TenantSummary;
use Modules\WhatsApp\Connections\WhatsAppConnections;
use Modules\WhatsApp\Enums\OptInSource;
use Modules\WhatsApp\Models\WhatsAppConnection;
use Modules\WhatsApp\OptIns\OptInRefused;
use Modules\WhatsApp\OptIns\WhatsAppOptIns;

/**
 * The "WhatsApp messages" panel on a tenant's record (Tenants'
 * TenantPagePanels; P05-06, Meta policy): whether the tenant agreed to the
 * agency's WhatsApp messages, at which number, since when and how; a
 * member who asked the tenant in person records their consent, or that
 * they want no more. Shown only once the organization's WhatsApp is
 * connected, to members who may see the tenant (TenantDirectory, building
 * narrowing included); recording needs `tenants.portal_access`
 * (WhatsAppPolicy::recordOptIn).
 */
final class TenantOptIn extends Component
{
    #[Locked]
    public int $tenantId;

    #[Locked]
    public int $organizationId;

    public bool $confirmed = false;

    #[Locked]
    public ?string $notice = null;

    public function mount(int $tenantId, OrganizationContext $context): void
    {
        $this->tenantId = $tenantId;
        $this->organizationId = $context->id();
    }

    public function hydrate(OrganizationContext $context): void
    {
        abort_unless($context->has() && $context->id() === $this->organizationId, 404);
    }

    #[BlockedWhileImpersonating]
    public function optIn(WhatsAppOptIns $optIns): void
    {
        $this->tenantOrFail();
        $this->authorize('recordOptIn', WhatsAppConnection::class);
        $this->resetErrorBag();

        if (! $this->confirmed) {
            $this->addError('confirmed', __('Tick the box to confirm the tenant agreed.'));

            return;
        }

        try {
            $optIn = $optIns->optIn($this->tenantId, OptInSource::AgentRecorded, $this->user());
        } catch (OptInRefused $refused) {
            $this->addError('confirmed', $refused->getMessage());

            return;
        }

        $this->confirmed = false;
        $this->notice = __('Recorded: WhatsApp messages go to :phone.', ['phone' => $optIn->phone]);
    }

    #[BlockedWhileImpersonating]
    public function optOut(WhatsAppOptIns $optIns): void
    {
        $this->tenantOrFail();
        $this->authorize('recordOptIn', WhatsAppConnection::class);
        $optIns->optOut($this->tenantId, OptInSource::AgentRecorded, $this->user());
        $this->notice = __('Recorded: no more WhatsApp messages to this tenant. Reminders go by SMS where they can.');
    }

    public function render(WhatsAppConnections $connections, WhatsAppOptIns $optIns): View
    {
        $tenant = app(TenantDirectory::class)->find($this->tenantId, $this->user());
        $connection = $connections->current();

        if ($tenant === null || ! $connection instanceof WhatsAppConnection || ! $connection->connected()) {
            return view('whatsapp::livewire.hidden-panel');
        }

        $latest = $optIns->latest($this->tenantId);
        $number = $optIns->numberOf($tenant);

        return view('whatsapp::livewire.tenant-opt-in', [
            'tenant' => $tenant,
            'number' => $number,
            'latest' => $latest,
            'current' => $latest !== null && $latest->open() && $latest->phone === $number,
            'working' => $connections->working() !== null,
            'canRecord' => ! $tenant->archived && $this->user()->can('recordOptIn', WhatsAppConnection::class),
        ]);
    }

    private function tenantOrFail(): TenantSummary
    {
        $tenant = app(TenantDirectory::class)->find($this->tenantId, $this->user());
        abort_if($tenant === null, 404);

        return $tenant;
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
