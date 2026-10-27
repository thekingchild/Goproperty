<?php

declare(strict_types=1);

namespace Modules\OnlinePayments\Connections;

use App\Enums\ChecklistItem;
use App\Models\Organization;
use App\Models\User;
use App\Onboarding\OnboardingChecklist;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Modules\OnlinePayments\Enums\ConnectionMode;
use Modules\OnlinePayments\Enums\ConnectionStatus;
use Modules\OnlinePayments\Models\PaystackConnection;
use Modules\OnlinePayments\Paystack\KeyTest;
use Modules\OnlinePayments\Paystack\PaystackClient;

/**
 * The current organization's own Paystack connection
 * (ONBOARDING_COMPLIANCE.md § 4.2, ARCHITECTURE.md § 10): the only writer
 * of PaystackConnection and of the organization's online-payment cap.
 * Connect (keys checked for shape, then tried against Paystack), test
 * again, rotate the webhook address, disconnect (the keys are forgotten),
 * set the cap.
 *
 * Org-owners only (`organization.payment_settings` through
 * PaystackConnectionPolicy, checked here too): the keys decide where
 * tenants' online payments settle, so administrators, agents and
 * impersonating GoProperty staff never see or change them. Every change is
 * audited under log `payment_settings` with the keys masked; the secret
 * key never leaves the server (encrypted at rest, never sent back to the
 * browser, never logged).
 */
final readonly class PaystackConnections
{
    public const string AUDIT_LOG = 'payment_settings';

    /** The online-payment cap when the organization has set none (ONBOARDING_COMPLIANCE.md § 4.2): ₦1,000,000. */
    public const int DEFAULT_CAP_NAIRA = 1_000_000;

    /** The highest cap an organization may set: ₦100,000,000. */
    public const int MAX_CAP_NAIRA = 100_000_000;

    /** The lowest: ₦1,000. */
    public const int MIN_CAP_NAIRA = 1_000;

    private const string PUBLIC_KEY = '/^pk_(test|live)_[A-Za-z0-9]{20,120}$/';

    private const string SECRET_KEY = '/^sk_(test|live)_[A-Za-z0-9]{20,120}$/';

    public function __construct(
        private PaystackClient $paystack,
        private OnboardingChecklist $checklist,
    ) {}

    /** The organization's connection, in whatever state, or null when it never connected. */
    public function current(): ?PaystackConnection
    {
        return PaystackConnection::query()->first();
    }

    /** The largest outstanding amount tenants may pay online (organizations.online_payment_cap_kobo, else the default). */
    public function cap(?Organization $organization = null): Money
    {
        $organization ??= $this->context()->get();

        return $organization->online_payment_cap_kobo ?? Money::naira(self::DEFAULT_CAP_NAIRA);
    }

    /**
     * Saves the keys (new, or replacing the ones saved) after Paystack
     * accepted the secret key; when Paystack cannot be reached they are
     * saved as "not tested yet" and online payment waits for a test.
     *
     * @throws ConnectionRefused
     */
    public function connect(User $by, string $publicKey, string $secretKey): PaystackConnection
    {
        Gate::forUser($by)->authorize('manage', PaystackConnection::class);
        $publicKey = trim($publicKey);
        $secretKey = trim($secretKey);
        $mode = $this->checkKeys($publicKey, $secretKey);

        // An HTTP call: made before the transaction, never holding a lock.
        $test = $this->paystack->test($secretKey);

        if ($test === KeyTest::Refused) {
            throw new ConnectionRefused('secretKey', __('Paystack did not accept this secret key. Copy it again from your Paystack dashboard (Settings → API Keys & Webhooks).'));
        }

        return DB::transaction(function () use ($by, $publicKey, $secretKey, $mode, $test): PaystackConnection {
            $this->lockOrganization();
            $connection = PaystackConnection::query()->lockForUpdate()->first();
            $replacing = $connection instanceof PaystackConnection && $connection->connected();
            $connection ??= $this->newConnection();

            $connection->public_key = $publicKey;
            $connection->secret_key = $secretKey;
            $connection->secret_key_last4 = substr($secretKey, -4);
            $connection->mode = $mode;
            $connection->status = $test === KeyTest::Accepted ? ConnectionStatus::Active : ConnectionStatus::PendingTest;
            $connection->last_tested_at = $test === KeyTest::Accepted ? now() : null;
            $connection->last_test_error = $test === KeyTest::Accepted ? null : 'Paystack could not be reached to test the keys.';
            $connection->connected_by_user_id = $by->id;
            $connection->connected_at = now();
            $connection->disconnected_at = null;
            $connection->save();

            $this->audit($replacing ? 'paystack.keys_replaced' : 'paystack.connected', $connection, $by, $replacing ? 'Paystack keys replaced' : 'Paystack account connected');
            $this->completeChecklist($connection, $by);

            return $connection;
        });
    }

    /**
     * Tries the saved secret key again: accepted → active, refused →
     * failing (online payment stops being offered), Paystack unreachable →
     * no change.
     *
     * @throws ConnectionRefused
     */
    public function test(User $by): KeyTest
    {
        Gate::forUser($by)->authorize('manage', PaystackConnection::class);
        $connection = $this->connectedOrRefuse();
        $result = $this->paystack->test((string) $connection->secret_key);

        DB::transaction(function () use ($by, $connection, $result): void {
            $connection = PaystackConnection::query()->lockForUpdate()->findOrFail($connection->id);

            if (! $connection->connected()) {
                return;
            }

            if ($result === KeyTest::Unavailable) {
                $connection->last_test_error = 'Paystack could not be reached to test the keys.';
            } else {
                $connection->status = $result === KeyTest::Accepted ? ConnectionStatus::Active : ConnectionStatus::Failing;
                $connection->last_tested_at = now();
                $connection->last_test_error = $result === KeyTest::Accepted ? null : 'Paystack did not accept the secret key.';
            }

            $connection->save();
            $this->audit('paystack.tested', $connection, $by, 'Paystack connection tested', ['result' => $result->value]);
            $this->completeChecklist($connection, $by);
        });

        return $result;
    }

    /**
     * A new webhook address: the old one stops working at once (404), so
     * the owner pastes the new one into Paystack.
     *
     * @throws ConnectionRefused
     */
    public function rotateWebhookToken(User $by): PaystackConnection
    {
        Gate::forUser($by)->authorize('manage', PaystackConnection::class);
        $this->connectedOrRefuse();

        return DB::transaction(function () use ($by): PaystackConnection {
            $connection = PaystackConnection::query()->lockForUpdate()->firstOrFail();
            $connection->webhook_token = self::newWebhookToken();
            $connection->save();
            $this->audit('paystack.webhook_rotated', $connection, $by, 'Paystack webhook address changed');

            return $connection;
        });
    }

    /**
     * Forgets the keys: no online payment is offered and webhooks to the
     * address are refused (404). Payments already recorded stay.
     *
     * @throws ConnectionRefused
     */
    public function disconnect(User $by): PaystackConnection
    {
        Gate::forUser($by)->authorize('manage', PaystackConnection::class);
        $this->connectedOrRefuse();

        return DB::transaction(function () use ($by): PaystackConnection {
            $connection = PaystackConnection::query()->lockForUpdate()->firstOrFail();
            $connection->public_key = null;
            $connection->secret_key = null;
            $connection->secret_key_last4 = null;
            $connection->status = ConnectionStatus::Disconnected;
            $connection->disconnected_at = now();
            $connection->save();
            $this->audit('paystack.disconnected', $connection, $by, 'Paystack account disconnected');

            return $connection;
        });
    }

    /**
     * Sets the largest bill tenants may pay online (null: the default).
     * Bills owing more show bank-transfer details only.
     *
     * @throws ConnectionRefused
     */
    public function setCap(User $by, ?Money $cap): void
    {
        Gate::forUser($by)->authorize('manage', PaystackConnection::class);

        if ($cap !== null && ($cap->lessThan(Money::naira(self::MIN_CAP_NAIRA)) || $cap->greaterThan(Money::naira(self::MAX_CAP_NAIRA)))) {
            throw new ConnectionRefused('cap', __('Enter a limit between :min and :max.', ['min' => Money::naira(self::MIN_CAP_NAIRA)->format(), 'max' => Money::naira(self::MAX_CAP_NAIRA)->format()]));
        }

        DB::transaction(function () use ($by, $cap): void {
            $organization = Organization::query()->whereKey($this->context()->id())->lockForUpdate()->firstOrFail();
            $before = $organization->online_payment_cap_kobo;
            $organization->online_payment_cap_kobo = $cap;
            // One entry, this one, instead of the model's own "updated".
            $organization->disableLogging();
            $organization->save();
            $organization->enableLogging();

            activity(self::AUDIT_LOG)
                ->event('paystack.online_cap_changed')
                ->performedOn($organization)
                ->causedBy($by)
                ->withProperties(['before_kobo' => $before?->kobo, 'after_kobo' => $cap?->kobo])
                ->log('Online payment limit changed');
        });
    }

    /** 48 random letters and digits: the organization's part of /webhooks/paystack/{token}. */
    public static function newWebhookToken(): string
    {
        return strtolower(Str::random(48));
    }

    /** @throws ConnectionRefused */
    private function checkKeys(string $publicKey, string $secretKey): ConnectionMode
    {
        if (preg_match(self::PUBLIC_KEY, $publicKey, $public) !== 1) {
            throw new ConnectionRefused('publicKey', __('Enter the public key from your Paystack dashboard: it starts with pk_live_ (or pk_test_).'));
        }

        if (preg_match(self::SECRET_KEY, $secretKey, $secret) !== 1) {
            throw new ConnectionRefused('secretKey', __('Enter the secret key from your Paystack dashboard: it starts with sk_live_ (or sk_test_).'));
        }

        if ($public[1] !== $secret[1]) {
            throw new ConnectionRefused('secretKey', __('Use the public and secret keys of the same mode: both live, or both test.'));
        }

        $mode = ConnectionMode::from($secret[1]);

        // A test-mode "payment" moves no money: on a live site it would confirm bills nobody paid.
        if ($mode === ConnectionMode::Test && app()->environment('production')) {
            throw new ConnectionRefused('secretKey', __('Use your live keys (pk_live_, sk_live_). Test keys move no money.'));
        }

        return $mode;
    }

    /** @throws ConnectionRefused */
    private function connectedOrRefuse(): PaystackConnection
    {
        $connection = $this->current();

        if (! $connection instanceof PaystackConnection || ! $connection->connected()) {
            throw new ConnectionRefused('connection', __('Connect your Paystack account first.'));
        }

        return $connection;
    }

    private function newConnection(): PaystackConnection
    {
        $connection = new PaystackConnection;
        $connection->webhook_token = self::newWebhookToken();

        return $connection;
    }

    private function completeChecklist(PaystackConnection $connection, User $by): void
    {
        if ($connection->active()) {
            $this->checklist->complete(ChecklistItem::Collections, $by, ['paystack' => true]);
        }
    }

    /** The organization worked in now (resolved per call: this service may outlive a request, e.g. in a queue worker). */
    private function context(): OrganizationContext
    {
        return app(OrganizationContext::class);
    }

    /** Serialises connection changes of one organization (one row per organization). */
    private function lockOrganization(): void
    {
        Organization::query()->whereKey($this->context()->id())->lockForUpdate()->first();
    }

    /** @param  array<string, mixed>  $properties */
    private function audit(string $event, PaystackConnection $connection, User $by, string $description, array $properties = []): void
    {
        activity(self::AUDIT_LOG)
            ->event($event)
            ->performedOn($connection)
            ->causedBy($by)
            ->withProperties([
                'mode' => $connection->mode?->value,
                'status' => $connection->status->value,
                'public_key' => $connection->maskedPublicKey(),
                'secret_key' => $connection->maskedSecretKey(),
                ...$properties,
            ])
            ->log($description);
    }
}
