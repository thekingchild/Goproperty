<?php

declare(strict_types=1);

namespace App\Platform\Webhooks;

use App\Policies\PaystackWebhookDeliveryPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The console's read model of deliveries to GoProperty's OWN Paystack
 * webhook, `/webhooks/paystack` (P06-03; `webhook_log` rows with no
 * organization and provider `paystack`; BILLING_PAYMENTS.md § 4.2): what
 * arrived, whether its signature matched, and what processing made of it.
 * Organizations' own Paystack deliveries and the email providers' are not
 * shown. Read-only: deliveries are written by SaasBilling's webhook and its
 * processing, and replayed only through its Contracts\PlatformWebhookReplays.
 * The body is never shown (it names the payer).
 *
 * @property int $id
 * @property string|null $event
 * @property string|null $reference
 * @property bool $signature_valid
 * @property string|null $ip_address
 * @property Carbon|null $received_at
 * @property Carbon|null $processed_at
 * @property string|null $outcome
 * @property string|null $processing_error
 */
#[UsePolicy(PaystackWebhookDeliveryPolicy::class)]
final class PaystackWebhookDelivery extends Model
{
    protected $table = 'webhook_log';

    protected static function booted(): void
    {
        self::addGlobalScope('own_paystack', static fn (Builder $query) => $query->whereNull('organization_id')->where('provider', 'paystack'));
        self::saving(fn (): never => throw new LogicException('Webhook deliveries change only through their processing.'));
        self::deleting(fn (): never => throw new LogicException('Webhook deliveries are never deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
