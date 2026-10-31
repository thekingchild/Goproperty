<?php

declare(strict_types=1);

namespace Modules\Leasing\Livewire;

use App\Models\User;
use App\Settings\SettingsStore;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Leasing\Contracts\RentPaymentLimits;

/**
 * Settings → Rent payments, /o/{organization}/settings/payments/rent
 * (P03-17; docs/plans/rent-part-payments-2026-10-31.md): how many payments
 * a tenant may make on one rent bill, as the organization's default
 * (setting `leasing.max_rent_payments`): no limit, or 1 to 12. A lease
 * may have its own value (its page). Seen with `viewSettings`, saved by
 * owners and administrators (`updateProfile`, `organization.update`);
 * audited by the settings store. Each bill keeps one due date.
 */
#[Layout('layouts::app')]
#[Title('Rent payments')]
final class RentPaymentSettingsPage extends Component
{
    #[Locked]
    public int $organizationId;

    /** "" = no limit, else "1" … "12". */
    public string $limit = '';

    #[Locked]
    public ?string $status = null;

    public function boot(OrganizationContext $context): void
    {
        abort_unless($context->has(), 404);
    }

    public function hydrate(OrganizationContext $context): void
    {
        abort_unless($context->id() === $this->organizationId, 404);
    }

    public function mount(OrganizationContext $context, RentPaymentLimits $limits): void
    {
        $organization = $context->get();
        $this->organizationId = $organization->id;
        $this->authorize('viewSettings', $organization);
        $this->limit = (string) ($limits->organizationDefault() ?? '');
    }

    public function save(OrganizationContext $context, SettingsStore $settings, RentPaymentLimits $limits): void
    {
        $organization = $context->get();
        $this->authorize('updateProfile', $organization);
        $this->validate(['limit' => ['nullable', 'integer', 'between:1,'.RentPaymentLimits::MAX]], [], ['limit' => __('number of payments')]);
        $value = $this->limit === '' ? null : (int) $this->limit;

        if ($value === $limits->organizationDefault()) {
            $this->status = __('Nothing changed.');

            return;
        }

        // Audited by the settings store.
        $settings->set(RentPaymentLimits::SETTING, $value, $organization);
        $this->status = $value === null
            ? __('Saved. Tenants may pay rent bills in as many payments as they like, unless a lease says otherwise.')
            : trans_choice('Saved. Rent bills are paid in one payment, unless a lease says otherwise.|Saved. Tenants may pay a rent bill in up to :count payments, unless a lease says otherwise.', $value, ['count' => $value]);
    }

    public function render(OrganizationContext $context): View
    {
        return view('leasing::livewire.rent-payment-settings-page', [
            'canEdit' => $this->user()->can('updateProfile', $context->get()),
            'max' => RentPaymentLimits::MAX,
        ]);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
