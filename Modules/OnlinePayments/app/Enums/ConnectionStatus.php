<?php

declare(strict_types=1);

namespace Modules\OnlinePayments\Enums;

/**
 * Where the organization's Paystack connection stands
 * (DATABASE_SCHEMA.md § 4 `organization_paystack_connections`):
 *
 * - pending_test: keys saved, but Paystack could not be reached to test them yet;
 * - active: Paystack accepted the secret key at the last test; "Pay online" is offered;
 * - failing: Paystack refused the key at a later test (rotated or revoked there);
 * - disconnected: the owner disconnected; the keys are gone.
 *
 * Webhooks are accepted (and verified with the secret) in every state but
 * disconnected; tenants are offered online payment only while active.
 */
enum ConnectionStatus: string
{
    case PendingTest = 'pending_test';
    case Active = 'active';
    case Failing = 'failing';
    case Disconnected = 'disconnected';

    public function label(): string
    {
        return match ($this) {
            self::PendingTest => 'Not tested yet',
            self::Active => 'Connected',
            self::Failing => 'Paystack refused the keys',
            self::Disconnected => 'Not connected',
        };
    }

    public function connected(): bool
    {
        return $this !== self::Disconnected;
    }
}
