<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Enums;

/**
 * Where the organization's WhatsApp connection stands
 * (DATABASE_SCHEMA.md § 7 `organization_whatsapp_connections`):
 *
 * - pending_test: saved, but Meta could not be reached to check it yet;
 * - active: Meta accepted the access token for the phone number at the last check; messages go;
 * - failing: Meta refused the token (revoked or expired there) at a check, a sync or a send;
 * - disconnected: the owner disconnected; the token and app secret are gone.
 *
 * Status webhooks are checked with the app secret in every state but
 * disconnected; messages go only while active.
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
            self::PendingTest => 'Not checked yet',
            self::Active => 'Connected',
            self::Failing => 'Meta refused the access token',
            self::Disconnected => 'Not connected',
        };
    }

    public function connected(): bool
    {
        return $this !== self::Disconnected;
    }
}
