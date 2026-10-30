<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Connections;

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Modules\WhatsApp\Enums\ConnectionStatus;
use Modules\WhatsApp\Meta\CheckResult;
use Modules\WhatsApp\Meta\CloudApi;
use Modules\WhatsApp\Models\WhatsAppConnection;
use SensitiveParameter;

/**
 * The current organization's own WhatsApp Business account
 * (ARCHITECTURE.md § 6.7): the only writer of its credentials. Connect or
 * replace (IDs checked for shape, then the token tried on the phone number
 * with Meta), check again, make a new webhook verify token, disconnect
 * (token and app secret forgotten).
 *
 * Org-owners only (`whatsapp.connection` through WhatsAppPolicy, checked
 * here too), never while GoProperty staff impersonate. Every change is
 * audited under log `whatsapp` with the token masked; the token and app
 * secret never leave the server (encrypted at rest, never sent back to the
 * browser, never logged). WhatsApp is on the Growth plan and above.
 */
final readonly class WhatsAppConnections
{
    public const string AUDIT_LOG = 'whatsapp';

    private const string META_ID = '/^\d{5,32}$/';

    private const string TOKEN = '/^[A-Za-z0-9_\-|.]{20,1000}$/';

    private const string APP_SECRET = '/^[A-Za-z0-9]{16,64}$/';

    public function __construct(
        private CloudApi $meta,
        private WhatsAppPlan $plan,
    ) {}

    /** The organization's connection, in whatever state, or null when it never connected. */
    public function current(): ?WhatsAppConnection
    {
        return WhatsAppConnection::query()->first();
    }

    /** Messages may go by WhatsApp now: the plan allows it and the connection works. */
    public function working(?Organization $organization = null): ?WhatsAppConnection
    {
        $organization ??= $this->context()->get();
        $connection = $this->current();

        return $connection !== null && $connection->active() && $this->plan->allows($organization) ? $connection : null;
    }

    /**
     * Saves the account's details after Meta accepted the token for the
     * phone number; when Meta cannot be reached they are saved as "not
     * checked yet". Replacing: an empty token or app secret keeps the saved one.
     *
     * @throws ConnectionRefused
     */
    public function connect(
        User $by,
        string $wabaId,
        string $phoneNumberId,
        #[SensitiveParameter] string $accessToken,
        #[SensitiveParameter] string $appSecret,
    ): WhatsAppConnection {
        Gate::forUser($by)->authorize('connect', WhatsAppConnection::class);

        if (! $this->plan->allows($this->context()->get())) {
            throw new ConnectionRefused('connection', __('WhatsApp messages come with the Growth plan and above. Change your plan to connect WhatsApp.'));
        }

        $existing = $this->current();
        $keeping = $existing !== null && $existing->connected();
        $wabaId = trim($wabaId);
        $phoneNumberId = trim($phoneNumberId);
        $accessToken = trim($accessToken) !== '' ? trim($accessToken) : ($keeping ? (string) $existing->access_token : '');
        $appSecret = trim($appSecret) !== '' ? trim($appSecret) : ($keeping ? (string) $existing->app_secret : '');
        $this->checkShapes($wabaId, $phoneNumberId, $accessToken, $appSecret);

        $candidate = new WhatsAppConnection;
        $candidate->forceFill(['waba_id' => $wabaId, 'phone_number_id' => $phoneNumberId, 'access_token' => $accessToken, 'organization_id' => $this->context()->id()]);

        // An HTTP call: made before the transaction, never holding a lock.
        $check = $this->meta->check($candidate);

        if ($check->result === CheckResult::Refused) {
            throw new ConnectionRefused('accessToken', __('Meta did not accept this access token for that phone number ID: :error', ['error' => $check->error ?? '']));
        }

        return DB::transaction(function () use ($by, $wabaId, $phoneNumberId, $accessToken, $appSecret, $check): WhatsAppConnection {
            Organization::query()->whereKey($this->context()->id())->lockForUpdate()->first();
            $connection = WhatsAppConnection::query()->lockForUpdate()->first();
            $replacing = $connection instanceof WhatsAppConnection && $connection->connected();
            $connection ??= $this->newConnection();
            $accepted = $check->result === CheckResult::Accepted;

            $connection->waba_id = $wabaId;
            $connection->phone_number_id = $phoneNumberId;
            $connection->access_token = $accessToken;
            $connection->access_token_last4 = substr($accessToken, -4);
            $connection->app_secret = $appSecret;
            $connection->display_phone_number = $accepted ? $check->displayPhoneNumber : $connection->display_phone_number;
            $connection->verified_name = $accepted ? $check->verifiedName : $connection->verified_name;
            $connection->status = $accepted ? ConnectionStatus::Active : ConnectionStatus::PendingTest;
            $connection->last_tested_at = $accepted ? now() : null;
            $connection->last_test_error = $accepted ? null : 'Meta could not be reached to check the connection.';
            $connection->connected_by_user_id = $by->id;
            $connection->connected_at = now();
            $connection->disconnected_at = null;
            $connection->save();

            $this->audit($replacing ? 'whatsapp.credentials_replaced' : 'whatsapp.connected', $connection, $by, $replacing ? 'WhatsApp credentials replaced' : 'WhatsApp Business account connected');

            return $connection;
        });
    }

    /**
     * Tries the saved token again: accepted → active, refused → failing
     * (nothing goes by WhatsApp; reminders use their next channel), Meta
     * unreachable → no change.
     *
     * @throws ConnectionRefused
     */
    public function test(User $by): CheckResult
    {
        Gate::forUser($by)->authorize('connect', WhatsAppConnection::class);
        $connection = $this->connectedOrRefuse();
        $check = $this->meta->check($connection);

        DB::transaction(function () use ($by, $connection, $check): void {
            $connection = WhatsAppConnection::query()->lockForUpdate()->findOrFail($connection->id);

            if (! $connection->connected()) {
                return;
            }

            if ($check->result === CheckResult::Unavailable) {
                $connection->last_test_error = 'Meta could not be reached to check the connection.';
            } else {
                $accepted = $check->result === CheckResult::Accepted;
                $connection->status = $accepted ? ConnectionStatus::Active : ConnectionStatus::Failing;
                $connection->last_tested_at = now();
                $connection->last_test_error = $accepted ? null : mb_substr((string) $check->error, 0, 300);
                $connection->display_phone_number = $accepted ? ($check->displayPhoneNumber ?? $connection->display_phone_number) : $connection->display_phone_number;
                $connection->verified_name = $accepted ? ($check->verifiedName ?? $connection->verified_name) : $connection->verified_name;
            }

            $connection->save();
            $this->audit('whatsapp.tested', $connection, $by, 'WhatsApp connection checked', ['result' => $check->result->value]);
        });

        return $check->result;
    }

    /**
     * A new verify token for Meta's webhook set-up (the address stays):
     * the owner pastes it into the app's webhook settings when Meta asks.
     *
     * @throws ConnectionRefused
     */
    public function newVerifyToken(User $by): WhatsAppConnection
    {
        Gate::forUser($by)->authorize('connect', WhatsAppConnection::class);
        $this->connectedOrRefuse();

        return DB::transaction(function () use ($by): WhatsAppConnection {
            $connection = WhatsAppConnection::query()->lockForUpdate()->firstOrFail();
            $connection->webhook_verify_token = self::newVerifyTokenValue();
            $connection->save();
            $this->audit('whatsapp.verify_token_changed', $connection, $by, 'WhatsApp webhook verify token changed');

            return $connection;
        });
    }

    /**
     * Forgets the token and app secret: nothing goes by WhatsApp, status
     * calls are refused (404) and reminders use their next channel. The
     * templates, mappings and opt-ins stay for a later reconnection.
     *
     * @throws ConnectionRefused
     */
    public function disconnect(User $by): WhatsAppConnection
    {
        Gate::forUser($by)->authorize('connect', WhatsAppConnection::class);
        $this->connectedOrRefuse();

        return DB::transaction(function () use ($by): WhatsAppConnection {
            $connection = WhatsAppConnection::query()->lockForUpdate()->firstOrFail();
            $connection->access_token = null;
            $connection->access_token_last4 = null;
            $connection->app_secret = null;
            $connection->status = ConnectionStatus::Disconnected;
            $connection->disconnected_at = now();
            $connection->save();
            $this->audit('whatsapp.disconnected', $connection, $by, 'WhatsApp Business account disconnected');

            return $connection;
        });
    }

    /**
     * Meta refused the token while syncing or sending: the connection is
     * failing until a check passes (no user: a system change, audited).
     */
    public function markFailing(WhatsAppConnection $connection, string $error): void
    {
        DB::transaction(function () use ($connection, $error): void {
            $locked = WhatsAppConnection::query()->lockForUpdate()->find($connection->id);

            if (! $locked instanceof WhatsAppConnection || $locked->status !== ConnectionStatus::Active) {
                return;
            }

            $locked->status = ConnectionStatus::Failing;
            $locked->last_test_error = mb_substr($error, 0, 300);
            $locked->save();

            activity(self::AUDIT_LOG)
                ->event('whatsapp.failing')
                ->performedOn($locked)
                ->withProperties(['error' => $locked->last_test_error])
                ->log('Meta refused the WhatsApp access token');
        });
    }

    /** 40 random letters and digits: what Meta's webhook set-up sends back to prove the address is ours. */
    public static function newVerifyTokenValue(): string
    {
        return Str::random(40);
    }

    /** @throws ConnectionRefused */
    private function checkShapes(string $wabaId, string $phoneNumberId, string $accessToken, string $appSecret): void
    {
        if (preg_match(self::META_ID, $wabaId) !== 1) {
            throw new ConnectionRefused('wabaId', __('Enter the WhatsApp Business Account ID from Meta\'s WhatsApp Manager: digits only.'));
        }

        if (preg_match(self::META_ID, $phoneNumberId) !== 1) {
            throw new ConnectionRefused('phoneNumberId', __('Enter the phone number ID from your Meta app (WhatsApp → API Setup): digits only, not the phone number itself.'));
        }

        if (preg_match(self::TOKEN, $accessToken) !== 1) {
            throw new ConnectionRefused('accessToken', __('Enter the permanent access token of your Meta system user (it starts with EAA).'));
        }

        if (preg_match(self::APP_SECRET, $appSecret) !== 1) {
            throw new ConnectionRefused('appSecret', __('Enter your Meta app\'s secret (App settings → Basic → App secret).'));
        }
    }

    /** @throws ConnectionRefused */
    private function connectedOrRefuse(): WhatsAppConnection
    {
        $connection = $this->current();

        if (! $connection instanceof WhatsAppConnection || ! $connection->connected()) {
            throw new ConnectionRefused('connection', __('Connect your WhatsApp Business account first.'));
        }

        return $connection;
    }

    private function newConnection(): WhatsAppConnection
    {
        $connection = new WhatsAppConnection;
        $connection->webhook_ulid = strtolower((string) Str::ulid());
        $connection->webhook_verify_token = self::newVerifyTokenValue();

        return $connection;
    }

    /** The organization worked in now (resolved per call: this service may outlive a request, e.g. in a queue worker). */
    private function context(): OrganizationContext
    {
        return app(OrganizationContext::class);
    }

    /** @param  array<string, mixed>  $properties */
    private function audit(string $event, WhatsAppConnection $connection, User $by, string $description, array $properties = []): void
    {
        activity(self::AUDIT_LOG)
            ->event($event)
            ->performedOn($connection)
            ->causedBy($by)
            ->withProperties([
                'status' => $connection->status->value,
                'waba_id' => $connection->waba_id,
                'phone_number_id' => $connection->phone_number_id,
                'access_token' => $connection->maskedAccessToken(),
                ...$properties,
            ])
            ->log($description);
    }
}
