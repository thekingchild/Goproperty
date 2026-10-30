<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Modules\WhatsApp\Enums\ConnectionStatus;

/**
 * The organization's own WhatsApp Business Platform account
 * (ARCHITECTURE.md § 6.7, DATABASE_SCHEMA.md § 7
 * `organization_whatsapp_connections`): one per organization,
 * organization-scoped. The access token and app secret are encrypted
 * casts, hidden from arrays and JSON, and never shown back (the token
 * masked to its last four characters). Written only by
 * Connections\WhatsAppConnections (org-owners, `whatsapp.connection`;
 * never while staff impersonate) and, for its status and sync times, by
 * Templates\TemplateSync and the Cloud API sender.
 *
 * @property int $id
 * @property int $organization_id
 * @property string|null $waba_id
 * @property string|null $phone_number_id
 * @property string|null $display_phone_number
 * @property string|null $verified_name
 * @property string|null $access_token decrypted
 * @property string|null $access_token_last4
 * @property string|null $app_secret decrypted
 * @property string $webhook_ulid
 * @property string $webhook_verify_token
 * @property ConnectionStatus $status
 * @property CarbonImmutable|null $last_tested_at
 * @property string|null $last_test_error
 * @property CarbonImmutable|null $last_event_at
 * @property CarbonImmutable|null $templates_synced_at
 * @property string|null $last_sync_error
 * @property int|null $connected_by_user_id
 * @property CarbonImmutable|null $connected_at
 * @property CarbonImmutable|null $disconnected_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Hidden(['access_token', 'access_token_last4', 'app_secret', 'webhook_verify_token'])]
final class WhatsAppConnection extends Model
{
    use BelongsToOrganization;

    protected $table = 'organization_whatsapp_connections';

    /** @var list<string> */
    protected $fillable = [];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending_test'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'app_secret' => 'encrypted',
            'status' => ConnectionStatus::class,
            'last_tested_at' => 'datetime',
            'last_event_at' => 'datetime',
            'templates_synced_at' => 'datetime',
            'connected_at' => 'datetime',
            'disconnected_at' => 'datetime',
        ];
    }

    /** Credentials saved (not disconnected). */
    public function connected(): bool
    {
        return $this->status->connected()
            && $this->access_token !== null && $this->access_token !== ''
            && $this->phone_number_id !== null && $this->waba_id !== null;
    }

    /** Messages may go: Meta accepted the token at the last check. */
    public function active(): bool
    {
        return $this->status === ConnectionStatus::Active && $this->connected();
    }

    /** "EAAG····9xQz". */
    public function maskedAccessToken(): ?string
    {
        return $this->access_token_last4 !== null ? 'EAA····'.$this->access_token_last4 : null;
    }
}
