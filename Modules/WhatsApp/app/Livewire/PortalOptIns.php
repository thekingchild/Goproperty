<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Livewire;

use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Tenants\Contracts\PortalTenant;
use Modules\Tenants\Contracts\TenantPortalAccess;
use Modules\WhatsApp\Connections\WhatsAppConnections;
use Modules\WhatsApp\Enums\OptInSource;
use Modules\WhatsApp\Models\WhatsAppOptIn;
use Modules\WhatsApp\OptIns\OptInRefused;
use Modules\WhatsApp\OptIns\WhatsAppOptIns;

/**
 * WhatsApp messages on the account's Settings → Notifications (Core's
 * NotificationSettingsPanels; P05-06, Meta policy): for each agency the
 * account rents from whose WhatsApp works, whether it may send rent
 * reminders and notices by WhatsApp to the account's number. Agreeing
 * needs a verified number (ARCHITECTURE.md § 6.7: messages go to verified
 * numbers only); stopping takes effect at once. Organizations come from
 * Tenants' TenantPortalAccess (the account's own tenant profiles only).
 * The account's own choice: never changed during a GoProperty support
 * session. Shows nothing when no agency of the account has WhatsApp.
 */
final class PortalOptIns extends Component
{
    public ?string $notice = null;

    public ?string $refusal = null;

    #[BlockedWhileImpersonating]
    public function optIn(int $organizationId, WhatsAppOptIns $optIns): void
    {
        $user = $this->user();
        $tenancy = $this->tenancy($organizationId);
        $this->refusal = null;

        if ($tenancy->readOnly()) {
            $this->refusal = __(':agency cannot change anything in its portal for now.', ['agency' => $tenancy->organization->name]);

            return;
        }

        if (! $user->hasVerifiedPhone()) {
            $this->refusal = __('Your phone number must be verified first: open a link your agency sends you by WhatsApp, or ask them to confirm it in person.');

            return;
        }

        try {
            app(OrganizationContext::class)->run($tenancy->organization, static function () use ($tenancy, $optIns, $user): void {
                foreach ($tenancy->tenantProfileIds as $id) {
                    $optIns->optIn($id, OptInSource::Portal, $user);
                }
            });
        } catch (OptInRefused $refused) {
            $this->refusal = $refused->getMessage();

            return;
        }

        $this->notice = __(':agency may now send you reminders and notices by WhatsApp.', ['agency' => $tenancy->organization->name]);
    }

    #[BlockedWhileImpersonating]
    public function optOut(int $organizationId, WhatsAppOptIns $optIns): void
    {
        $user = $this->user();
        $tenancy = $this->tenancy($organizationId);

        app(OrganizationContext::class)->run($tenancy->organization, static function () use ($tenancy, $optIns, $user): void {
            foreach ($tenancy->tenantProfileIds as $id) {
                $optIns->optOut($id, OptInSource::Portal, $user);
            }
        });

        $this->refusal = null;
        $this->notice = __(':agency will send you no more WhatsApp messages.', ['agency' => $tenancy->organization->name]);
    }

    public function render(WhatsAppConnections $connections): View
    {
        $user = $this->user();
        $agencies = [];

        foreach ($this->tenancies() as $tenancy) {
            $row = app(OrganizationContext::class)->run($tenancy->organization, static function () use ($connections, $tenancy, $user): ?array {
                if ($connections->working() === null) {
                    return null;
                }

                $open = WhatsAppOptIn::query()->whereIn('tenant_profile_id', $tenancy->tenantProfileIds)->whereNull('opted_out_at')->where('phone', (string) $user->phone)->exists();

                return ['organization' => $tenancy->organization, 'optedIn' => $open, 'number' => $connections->current()?->display_phone_number, 'readOnly' => $tenancy->readOnly()];
            });

            if ($row !== null) {
                $agencies[] = $row;
            }
        }

        if ($agencies === []) {
            return view('whatsapp::livewire.hidden-panel');
        }

        return view('whatsapp::livewire.portal-opt-ins', [
            'agencies' => $agencies,
            'verified' => $user->hasVerifiedPhone(),
            'phone' => $user->phone,
        ]);
    }

    /** @return list<PortalTenant> */
    private function tenancies(): array
    {
        return app()->bound(TenantPortalAccess::class) ? app(TenantPortalAccess::class)->tenancies($this->user()) : [];
    }

    private function tenancy(int $organizationId): PortalTenant
    {
        foreach ($this->tenancies() as $tenancy) {
            if ($tenancy->organization->id === $organizationId) {
                return $tenancy;
            }
        }

        abort(404);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
