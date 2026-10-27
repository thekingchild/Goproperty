<?php

declare(strict_types=1);

namespace Modules\OnlinePayments\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Modules\OnlinePayments\Enums\WebhookOutcome;

/**
 * A delivery to the organization's webhook, /webhooks/paystack/{token}
 * (Core table `webhook_log`, DATABASE_SCHEMA.md § 2; BILLING_PAYMENTS.md
 * § 4.2): the raw body as received, whether its signature matched the
 * organization's secret key, and what processing did. Organization-scoped
 * (the platform's own Paystack rows, organization null, are never seen
 * here). Kept before processing; never deleted, and the body never changes.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $provider
 * @property string|null $event
 * @property string|null $reference
 * @property string $payload the raw body
 * @property bool $signature_valid
 * @property string|null $ip_address
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $processed_at
 * @property WebhookOutcome|null $outcome
 * @property string|null $processing_error
 */
final class PaystackWebhookLog extends Model
{
    use BelongsToOrganization;

    public const string PROVIDER = 'paystack';

    protected $table = 'webhook_log';

    /** @var list<string> */
    protected $fillable = [];

    /** @var array<string, mixed> */
    protected $attributes = ['provider' => self::PROVIDER, 'signature_valid' => false];

    protected static function booted(): void
    {
        self::updating(static function (self $log): void {
            if ($log->isDirty(['payload', 'signature_valid', 'event', 'reference', 'provider', 'received_at'])) {
                throw new LogicException('A webhook delivery is kept as it was received.');
            }
        });

        self::deleting(static fn (): never => throw new LogicException('Webhook deliveries are never deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'outcome' => WebhookOutcome::class,
        ];
    }
}
