<?php

declare(strict_types=1);

namespace Modules\OnlinePayments\Livewire;

use App\Enums\OrganizationStatus;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use App\Theme\ThemeContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\OnlinePayments\Connections\ConnectionRefused;
use Modules\OnlinePayments\Connections\PaystackConnections;
use Modules\OnlinePayments\Enums\TransactionStatus;
use Modules\OnlinePayments\Models\PaystackConnection;
use Modules\OnlinePayments\Models\PaystackTransaction;
use Modules\OnlinePayments\Paystack\KeyTest;

/**
 * Settings → Online payments, /o/{organization}/settings/paystack
 * (ONBOARDING_COMPLIANCE.md § 4.2, ARCHITECTURE.md § 10): the
 * organization's own Paystack account. Connect with the public and secret
 * keys (tried against Paystack first), see them masked, test the
 * connection again, copy or change the webhook address, disconnect, and set
 * the largest bill tenants may pay online. Payments held for review are
 * listed (the newest ten).
 *
 * Org-owners only: seeing needs `payment_settings.view`, changing
 * `organization.payment_settings` (PaystackConnectionPolicy). Both are
 * dropped while GoProperty staff impersonate a user and the whole component
 * is #[BlockedWhileImpersonating], so support neither sees nor changes the
 * keys. Read-only while the organization is suspended. The keys typed are
 * cleared from the component after every attempt, so the secret key never
 * goes back to the browser.
 */
#[Layout('layouts::app')]
#[Title('Online payments')]
#[BlockedWhileImpersonating]
final class PaystackSettingsPage extends Component
{
    #[Locked]
    public int $organizationId;

    public string $publicKey = '';

    public string $secretKey = '';

    /** The online-payment cap in naira as typed; empty = the default. */
    public string $cap = '';

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

    public function mount(OrganizationContext $context): void
    {
        $organization = $context->get();
        $this->organizationId = $organization->id;
        $this->authorize('viewAny', PaystackConnection::class);
        $this->cap = $organization->online_payment_cap_kobo !== null ? self::naira($organization->online_payment_cap_kobo) : '';
    }

    public function connect(PaystackConnections $connections): void
    {
        $this->authorize('manage', PaystackConnection::class);
        $this->resetErrorBag();
        $publicKey = $this->publicKey;
        $secretKey = $this->secretKey;
        // Never kept in the component: the secret key does not go back to the browser.
        $this->secretKey = '';

        try {
            $connection = $connections->connect($this->user(), $publicKey, $secretKey);
        } catch (ConnectionRefused $refused) {
            $this->status = null;
            $this->addError($refused->field, $refused->getMessage());

            return;
        }

        $this->publicKey = '';
        $this->status = $connection->active()
            ? __('Paystack accepted your keys. Now paste the webhook address below into your Paystack dashboard.')
            : __('Your keys are saved, but Paystack could not be reached to test them. Test the connection again in a few minutes; tenants are offered online payment once the test passes.');
    }

    public function test(PaystackConnections $connections): void
    {
        $this->authorize('manage', PaystackConnection::class);
        $this->resetErrorBag();

        $this->attempt(fn (): string => match ($connections->test($this->user())) {
            KeyTest::Accepted => __('Paystack accepted your keys.'),
            KeyTest::Refused => __('Paystack refused your secret key. Tenants are not offered online payment until you save working keys.'),
            KeyTest::Unavailable => __('Paystack could not be reached. Try again in a few minutes.'),
        });
    }

    public function rotate(PaystackConnections $connections): void
    {
        $this->authorize('manage', PaystackConnection::class);
        $this->attempt(function () use ($connections): string {
            $connections->rotateWebhookToken($this->user());

            return __('The webhook address changed. Paste the new one into your Paystack dashboard now: Paystack\'s calls to the old one are refused.');
        });
    }

    public function disconnect(PaystackConnections $connections): void
    {
        $this->authorize('manage', PaystackConnection::class);
        $this->attempt(function () use ($connections): string {
            $connections->disconnect($this->user());

            return __('Paystack disconnected and your keys removed. Tenants now see bank transfer details only.');
        });
    }

    public function saveCap(PaystackConnections $connections): void
    {
        $this->authorize('manage', PaystackConnection::class);
        $this->resetErrorBag();
        $typed = str_replace([',', ' ', "\u{00A0}", '₦'], '', trim($this->cap));

        if ($typed !== '' && preg_match('/^\d{1,12}(\.\d{1,2})?$/', $typed) !== 1) {
            $this->addError('cap', __('Enter an amount in naira, e.g. 500,000.'));

            return;
        }

        try {
            $connections->setCap($this->user(), $typed === '' ? null : Money::naira($typed));
        } catch (ConnectionRefused $refused) {
            $this->addError($refused->field, $refused->getMessage());

            return;
        }

        $this->status = $typed === ''
            ? __('Online payment limit set back to the standard :amount.', ['amount' => Money::naira(PaystackConnections::DEFAULT_CAP_NAIRA)->format()])
            : __('Bills owing up to :amount can now be paid online; larger ones show bank details only.', ['amount' => Money::naira($typed)->format()]);
    }

    public function render(PaystackConnections $connections, OrganizationContext $context): View
    {
        $connection = $connections->current();

        return view('onlinepayments::livewire.paystack-settings-page', [
            'connection' => $connection?->connected() ? $connection : null,
            'webhookUrl' => $connection?->connected() ? route('online-payments.webhook', ['token' => $connection->webhook_token]) : null,
            'capNow' => $connections->cap($context->get()),
            'defaultCap' => Money::naira(PaystackConnections::DEFAULT_CAP_NAIRA),
            'held' => PaystackTransaction::query()->where('status', TransactionStatus::Held)->latest('id')->limit(10)->get(),
            'canManage' => $this->user()->can('manage', PaystackConnection::class),
            'brand' => app(ThemeContext::class)->current()->name(),
            // Opened from the signup wizard's collections step: the way back to it.
            'setupUrl' => $context->get()->status === OrganizationStatus::Onboarding ? route('workspace.setup', $context->get()) : null,
        ]);
    }

    /** @param  callable(): string  $change */
    private function attempt(callable $change): void
    {
        try {
            $this->status = $change();
        } catch (ConnectionRefused $refused) {
            $this->status = null;
            $this->addError('connection', $refused->getMessage());
        }
    }

    private static function naira(Money $amount): string
    {
        return str_replace($amount->currency()->symbol(), '', $amount->format());
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
