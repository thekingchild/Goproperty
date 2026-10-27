<?php

declare(strict_types=1);

namespace Modules\OnlinePayments\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Modules\OnlinePayments\Enums\ConnectionMode;
use Modules\OnlinePayments\Enums\ConnectionStatus;

/**
 * The organization's own Paystack account (ONBOARDING_COMPLIANCE.md § 4.2,
 * DATABASE_SCHEMA.md § 4 `organization_paystack_connections`): one per
 * organization, organization-scoped. The secret key is an encrypted cast;
 * both keys and the webhook token are hidden from arrays and JSON, and
 * screens show the keys masked (maskedPublicKey(), maskedSecretKey()).
 * Written only by Connections\PaystackConnections (org-owners,
 * `organization.payment_settings`; never while staff impersonate).
 *
 * @property int $id
 * @property int $organization_id
 * @property string|null $public_key
 * @property string|null $secret_key decrypted
 * @property string|null $secret_key_last4
 * @property string $webhook_token
 * @property ConnectionMode|null $mode
 * @property ConnectionStatus $status
 * @property CarbonImmutable|null $last_tested_at
 * @property string|null $last_test_error
 * @property CarbonImmutable|null $last_event_at
 * @property int|null $connected_by_user_id
 * @property CarbonImmutable|null $connected_at
 * @property CarbonImmutable|null $disconnected_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Hidden(['public_key', 'secret_key', 'secret_key_last4', 'webhook_token'])]
final class PaystackConnection extends Model
{
    use BelongsToOrganization;

    protected $table = 'organization_paystack_connections';

    /** @var list<string> */
    protected $fillable = [];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending_test'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'secret_key' => 'encrypted',
            'mode' => ConnectionMode::class,
            'status' => ConnectionStatus::class,
            'last_tested_at' => 'datetime',
            'last_event_at' => 'datetime',
            'connected_at' => 'datetime',
            'disconnected_at' => 'datetime',
        ];
    }

    /** Keys saved (not disconnected). */
    public function connected(): bool
    {
        return $this->status->connected() && $this->secret_key !== null && $this->secret_key !== '';
    }

    /** Tenants may be offered online payment: Paystack accepted the keys at the last test. */
    public function active(): bool
    {
        return $this->status === ConnectionStatus::Active && $this->connected();
    }

    /** "pk_live_····3f9a". */
    public function maskedPublicKey(): ?string
    {
        if ($this->public_key === null || $this->public_key === '') {
            return null;
        }

        return self::mask($this->public_key, substr($this->public_key, -4));
    }

    /** "sk_live_····b21c". */
    public function maskedSecretKey(): ?string
    {
        if ($this->secret_key_last4 === null || $this->mode === null) {
            return null;
        }

        return 'sk_'.$this->mode->value.'_····'.$this->secret_key_last4;
    }

    private static function mask(string $key, string $last4): string
    {
        $prefix = preg_match('/^(pk|sk)_(test|live)_/', $key, $match) === 1 ? $match[0] : '';

        return $prefix.'····'.$last4;
    }
}
