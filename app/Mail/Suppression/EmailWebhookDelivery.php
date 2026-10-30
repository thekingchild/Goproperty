<?php

declare(strict_types=1);

namespace App\Mail\Suppression;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A call from the email provider's bounce and complaint webhook (P05-04),
 * kept in Core's `webhook_log` before it is processed (DATABASE_SCHEMA.md
 * § 2): the raw body, whether it was proved to come from the provider, and
 * what it did. Platform rows only (`organization_id` null; the scope keeps
 * other providers' rows out). Never deleted; the body never changes.
 *
 * @property int $id
 * @property string $provider
 * @property string|null $event
 * @property string $payload
 * @property bool $signature_valid
 * @property string|null $ip_address
 * @property Carbon|null $received_at
 * @property Carbon|null $processed_at
 * @property string|null $outcome
 */
final class EmailWebhookDelivery extends Model
{
    public const array PROVIDERS = ['postmark', 'mailgun'];

    protected $table = 'webhook_log';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::addGlobalScope('email_providers', static fn (Builder $query) => $query->whereNull('organization_id')->whereIn('provider', self::PROVIDERS));

        self::updating(static function (self $delivery): void {
            if ($delivery->isDirty(['payload', 'signature_valid', 'provider', 'received_at'])) {
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
        ];
    }
}
