<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Exports\Livewire\WithListExports;
use App\Models\SmsCreditEntry;
use App\Models\User;
use App\Sms\Credits\SmsCreditLedger;
use App\Sms\Credits\SmsCredits;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\SaasBilling\Contracts\CheckoutRefused;
use Modules\SaasBilling\Contracts\SmsCreditCheckout;

/**
 * Settings → SMS credits, /o/{organization}/settings/sms-credits (P05-03;
 * BILLING_PAYMENTS.md § 1.2): the balance, this month's allowance and what
 * is left of it (it resets on the 1st), the purchased credits (never
 * expire), the bundles to buy, the latest purchases, and the ledger (25 a
 * page, exportable). Every SMS to a tenant uses one credit; at zero SMS
 * stop and email and in-app reminders carry on.
 *
 * Seeing it needs `sms_credits.view` (owners, administrators; kept while
 * suspended and in support sessions). Buying needs `organization.billing`
 * too (owners; SmsCreditPolicy::buy), never while GoProperty staff
 * impersonate nor while the organization is suspended (read-only): the checkout is GoProperty's own Paystack, through
 * Modules\SaasBilling\Contracts\SmsCreditCheckout, and the credits arrive
 * only once Paystack confirms the payment.
 */
#[Layout('layouts::app')]
#[Title('SMS credits')]
final class SmsCreditsPage extends Component
{
    use WithListExports, WithPagination;

    public const int PER_PAGE = 25;

    #[Locked]
    public int $organizationId;

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
        $this->authorize('viewAny', SmsCreditEntry::class);
    }

    #[BlockedWhileImpersonating]
    public function buy(int $credits): void
    {
        $this->authorize('buy', SmsCreditEntry::class);
        $this->resetErrorBag('buy');
        $checkout = $this->checkout();

        if ($checkout === null) {
            $this->addError('buy', __('Buying SMS credits online is not available yet. Contact support to add credits.'));

            return;
        }

        try {
            $url = $checkout->start($this->user(), $credits, route('workspace.settings.sms-credits.paid', ['organization' => app(OrganizationContext::class)->get()->slug]));
        } catch (CheckoutRefused $refused) {
            $this->addError('buy', $refused->getMessage());

            return;
        }

        $this->redirect($url);
    }

    public function render(OrganizationContext $context, SmsCredits $credits, SmsCreditLedger $ledger): View
    {
        $user = $this->user();
        $checkout = $this->checkout();
        $status = session('sms_credits_status');

        return view('livewire.settings.sms-credits-page', [
            'organization' => $context->get(),
            'summary' => $credits->summary(),
            'resetsOn' => SmsCredits::nextReset(),
            'entries' => $ledger->query($user, [])->paginate(self::PER_PAGE),
            'canBuy' => $user->can('buy', SmsCreditEntry::class),
            'available' => $checkout?->available() ?? false,
            'bundles' => $checkout?->bundles() ?? [],
            'purchases' => $checkout?->purchases() ?? [],
            'status' => is_array($status) && count($status) === 2 ? $status : null,
        ]);
    }

    protected function listExports(): array
    {
        return ['sms_credit_entries' => []];
    }

    private function checkout(): ?SmsCreditCheckout
    {
        return app()->bound(SmsCreditCheckout::class) ? app(SmsCreditCheckout::class) : null;
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
